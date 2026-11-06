<?php

declare(strict_types=1);

namespace App\Services\Procurement\Tuning;

use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use App\Repositories\Llm\LlmCallReadRepository;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Критерий приёмки патча промта между двумя `EvalRun` (+ правило отбора):
 * подвыборки `tune`/`holdout` вычисляются из `estimates/eval-splits.json` по членству в `holdout`
 * (та же семантика, что `estimate:eval:run --split`).
 *
 * @phpstan-type PerFieldMetrics array<string, array{f1: float}>
 * @phpstan-type ResultRow array{code: string, f1: float, row_coverage: float, per_field: PerFieldMetrics}
 * @phpstan-type SubsampleAggregate array{f1: float, row_coverage: float, per_field: array<string, float>, examples: int}
 * @phpstan-type SubsampleDelta array{base: SubsampleAggregate, candidate: SubsampleAggregate, delta: array{f1: float, row_coverage: float, per_field: array<string, float>}}
 * @phpstan-type Subsamples array<string, SubsampleDelta>
 * @phpstan-type UsageProfile array{calls: int, prompt_tokens: int, completion_tokens: int, avg_prompt_tokens: float, cost_micros: int}
 */
final readonly class EvalRunComparator
{
    public const string CRITERION_PATCH = 'patch';

    public const string CRITERION_NO_REGRESSION = 'no-regression';

    public const string CRITERION_NOISE_AWARE = 'noise-aware';

    public function __construct(
        private LlmCallReadRepository $llmCalls,
        private EvalGateEvaluator $gateEvaluator = new EvalGateEvaluator,
    ) {}

    /**
     * @return array{
     * base_run_id: int, candidate_run_id: int,
     * subsamples: array<string, array{
     * base: array{f1: float, row_coverage: float, per_field: array<string, float>, examples: int},
     * candidate: array{f1: float, row_coverage: float, per_field: array<string, float>, examples: int},
     * delta: array{f1: float, row_coverage: float, per_field: array<string, float>},
     * }>,
     * per_fixture: array<string, float>,
     * verdict: 'accept'|'reject',
     * reasons: list<string>,
     * noise_basis?: array{
     * pair_base_id: int, pair_candidate_id: int,
     * subsample_thresholds: array<string, array{f1: float, row_coverage: float}>,
     * per_fixture_threshold: float, per_fixture_worst_code: string,
     * },
     * profile?: array{
     * base: array{calls: int, prompt_tokens: int, completion_tokens: int, avg_prompt_tokens: float, cost_micros: int},
     * candidate: array{calls: int, prompt_tokens: int, completion_tokens: int, avg_prompt_tokens: float, cost_micros: int},
     * output_drop: int, output_drop_noise_threshold: int,
     * },
     * }
     */
    public function compare(
        EvalRun $base,
        EvalRun $candidate,
        string $criterion = self::CRITERION_PATCH,
        ?EvalRun $noisePairBase = null,
        ?EvalRun $noisePairCandidate = null,
    ): array {
        if (! in_array($criterion, [self::CRITERION_PATCH, self::CRITERION_NO_REGRESSION, self::CRITERION_NOISE_AWARE], true)) {
            throw new RuntimeException("Неизвестный критерий сравнения «{$criterion}» (ожидается «".self::CRITERION_PATCH.'», «'.self::CRITERION_NO_REGRESSION.'» или «'.self::CRITERION_NOISE_AWARE.'»).');
        }

        if ($criterion === self::CRITERION_NOISE_AWARE && ($noisePairBase === null || $noisePairCandidate === null)) {
            throw new RuntimeException('Режим «'.self::CRITERION_NOISE_AWARE.'» требует нулевую пару (`--noise-pair=<id>,<id>`) — тихого дефолта нет.');
        }

        [$baseResults, $candidateResults] = $this->loadComparablePair($base, $candidate);
        $holdoutCodes = $this->loadHoldoutCodes();

        $subsamples = $this->gateEvaluator->buildSubsamples(
            baseResults: $baseResults,
            candidateResults: $candidateResults,
            holdoutCodes: $holdoutCodes,
            baseId: $base->id,
            candidateId: $candidate->id,
            includeOverall: $criterion === self::CRITERION_NOISE_AWARE,
        );

        $perFixture = $this->gateEvaluator->perFixtureDeltas($baseResults, $candidateResults);

        $noiseThresholds = null;
        $noisePairIds = null;
        $profile = null;

        if ($criterion === self::CRITERION_NOISE_AWARE) {
            /** @var EvalRun $noisePairBase */
            /** @var EvalRun $noisePairCandidate */
            [$noiseBaseResults, $noiseCandidateResults] = $this->loadComparablePair($noisePairBase, $noisePairCandidate);
            $noiseThresholds = $this->gateEvaluator->computeNoiseThresholds($noiseBaseResults, $noiseCandidateResults, $holdoutCodes);
            $noisePairIds = ['base' => $noisePairBase->id, 'candidate' => $noisePairCandidate->id];
            $profile = $this->buildProfile($base, $candidate, $noisePairBase, $noisePairCandidate);
        }

        $verdict = $this->gateEvaluator->evaluateGates($subsamples, $perFixture, $criterion, $noiseThresholds, $noisePairIds);

        $result = [
            'base_run_id' => $base->id,
            'candidate_run_id' => $candidate->id,
            'subsamples' => $subsamples,
            'per_fixture' => $perFixture,
            'verdict' => $verdict['verdict'],
            'reasons' => $verdict['reasons'],
        ];

        if ($criterion === self::CRITERION_NOISE_AWARE) {
            /** @var array{base: int, candidate: int} $noisePairIds */
            /** @var array{subsamples: array<string, array{f1: float, row_coverage: float}>, per_fixture_threshold: float, per_fixture_worst_code: string} $noiseThresholds */
            $result['noise_basis'] = [
                'pair_base_id' => $noisePairIds['base'],
                'pair_candidate_id' => $noisePairIds['candidate'],
                'subsample_thresholds' => $noiseThresholds['subsamples'],
                'per_fixture_threshold' => $noiseThresholds['per_fixture_threshold'],
                'per_fixture_worst_code' => $noiseThresholds['per_fixture_worst_code'],
            ];
            $result['profile'] = $profile;
        }

        return $result;
    }

    private function assertComparable(EvalRun $base, EvalRun $candidate): void
    {
        if ($base->provider !== $candidate->provider || $base->model !== $candidate->model) {
            throw new RuntimeException(
                "Прогоны #{$base->id}/#{$candidate->id} несравнимы: разный маршрут моделей ({$base->provider}/{$base->model} против {$candidate->provider}/{$candidate->model}).",
            );
        }

        if ($base->prompt_version_set === null || $candidate->prompt_version_set === null) {
            throw new RuntimeException(
                "Прогоны #{$base->id}/#{$candidate->id} несравнимы: prompt_version_set пуст либо разъехался внутри одного из прогонов.",
            );
        }
    }

    /**
     * @return array{0: Collection<int, ResultRow>, 1: Collection<int, ResultRow>}
     */
    private function loadComparablePair(EvalRun $a, EvalRun $b): array
    {
        $this->assertComparable($a, $b);

        $aResults = $this->loadResults($a);
        $bResults = $this->loadResults($b);

        $aCodes = $aResults->pluck('code')->sort()->values()->all();
        $bCodes = $bResults->pluck('code')->sort()->values()->all();

        if ($aCodes !== $bCodes) {
            throw new RuntimeException("Прогоны #{$a->id}/#{$b->id} несравнимы: разный состав эталонов подвыборки.");
        }

        return [$aResults, $bResults];
    }

    /**
     * @return Collection<int, ResultRow>
     */
    private function loadResults(EvalRun $run): Collection
    {
        return EvalResult::query()
            ->where('eval_run_id', $run->id)
            ->where('metric', 'f1')
            ->with('datasetExample.fixture')
            ->get()
            ->map(fn (EvalResult $result): array => $this->gateEvaluator->parseResultRow(
                resultId: $result->id,
                code: $result->datasetExample?->fixture?->code,
                value: $result->value,
                rawDetails: $result->details?->all() ?? [],
            ));
    }

    /**
     * @return list<string>
     */
    private function loadHoldoutCodes(): array
    {
        $path = $this->gateEvaluator->stringValue(config('ai.fixtures.splits_path'), 'ai.fixtures.splits_path');

        if (! is_file($path)) {
            throw new RuntimeException("Файл разбиения не найден: {$path}. Прогони `estimate:eval:run --write-splits`.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Файл разбиения не удалось прочитать: {$path}.");
        }

        $decoded = $this->gateEvaluator->jsonObject(json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR), 'eval splits');
        $holdout = $decoded['holdout'] ?? [];

        if (! is_array($holdout)) {
            throw new RuntimeException('eval splits holdout должен быть массивом кодов.');
        }

        $codes = [];

        foreach ($holdout as $code) {
            $codes[] = $this->gateEvaluator->stringValue($code, 'eval splits holdout code');
        }

        return $codes;
    }

    /**
     * @return array{
     *     base: UsageProfile,
     *     candidate: UsageProfile,
     *     output_drop: int,
     *     output_drop_noise_threshold: int,
     * }
     */
    private function buildProfile(EvalRun $base, EvalRun $candidate, EvalRun $noisePairBase, EvalRun $noisePairCandidate): array
    {
        $baseProfile = $this->profileFor($base);
        $candidateProfile = $this->profileFor($candidate);
        $noiseBaseProfile = $this->profileFor($noisePairBase);
        $noiseCandidateProfile = $this->profileFor($noisePairCandidate);

        return [
            'base' => $baseProfile,
            'candidate' => $candidateProfile,
            'output_drop' => $baseProfile['completion_tokens'] - $candidateProfile['completion_tokens'],
            'output_drop_noise_threshold' => abs($noiseCandidateProfile['completion_tokens'] - $noiseBaseProfile['completion_tokens']),
        ];
    }

    /**
     * @return UsageProfile
     */
    private function profileFor(EvalRun $run): array
    {
        if ($run->started_at === null || $run->finished_at === null) {
            throw new RuntimeException("Прогон #{$run->id} не завершён (нет started_at/finished_at) — профиль вызовов не посчитать.");
        }

        $rows = $this->llmCalls->usageByAxes(from: $run->started_at, to: $run->finished_at, axes: []);
        $row = $rows[0] ?? ['calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'cost_micros' => 0];

        return [
            'calls' => $row['calls'],
            'prompt_tokens' => $row['prompt_tokens'],
            'completion_tokens' => $row['completion_tokens'],
            'avg_prompt_tokens' => $row['calls'] > 0 ? round($row['prompt_tokens'] / $row['calls'], 1) : 0.0,
            'cost_micros' => $row['cost_micros'],
        ];
    }
}
