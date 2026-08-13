<?php

declare(strict_types=1);

namespace App\Services\Procurement\Tuning;

use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use App\Repositories\Llm\LlmCallReadRepository;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Критерий приёмки патча промта между двумя `EvalRun` (P7.5, D63 + правило отбора D119/D120):
 * подвыборки `tune`/`holdout` вычисляются из `estimates/eval-splits.json` по членству в `holdout`
 * (та же семантика, что `estimate:eval:run --split`) — сравниваемые прогоны предполагаются прогнанными
 * `--split=all`, иначе одна из подвыборок останется пустой и `compare()` откажется считать критерий.
 * Числа для порогов (а)–(г) — макро-усреднение per-example `f1`/`row_coverage` внутри подвыборки,
 * тот же приём, что `EvalRunCommand::aggregate()` для прогона целиком.
 *
 * Режим `no-regression` (P2.2, D24/D91) — та же проверка порогов ПАДЕНИЯ (б)–(г), но БЕЗ
 * требования прироста (а) `TUNE_F1_MIN_GAIN`: перенос семантики докблока в атрибут (P2 items)
 * не обещает улучшения, только «не хуже», и штатный режим `patch` дал бы на нём ложный `reject`.
 * Величины порогов (б)–(г) не меняются ни в одном режиме (D24) — режим только выключает проверку
 * (а), остальной `verdict()` общий для обоих режимов.
 *
 * Режим `noise-aware` (P2.13, D108/D109) — те же оси (б)–(г), но порог каждой оси ВЫЧИСЛЯЕТСЯ из
 * названной опцией `--noise-pair` нулевой пары прогонов (модуль дельты той же оси на паре без
 * изменения — `no-regression` на этом же корпусе отвергает даже нулевое изменение, D108) вместо
 * констант `HOLDOUT_F1_MAX_DROP`/`ROW_COVERAGE_MAX_DROP`/`PER_FIXTURE_F1_MAX_DROP`. Добавляет
 * агрегат `overall` (все эталоны прогона, без деления на `tune`/`holdout`) в `subsamples` и
 * информационный (не влияющий на вердикт) профиль вызовов `llm_calls` обоих сравниваемых прогонов.
 */
/**
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

    private const float TUNE_F1_MIN_GAIN = 0.01;

    private const float HOLDOUT_F1_MAX_DROP = 0.005;

    private const float ROW_COVERAGE_MAX_DROP = 0.005;

    private const float PER_FIXTURE_F1_MAX_DROP = 0.05;

    public function __construct(
        private LlmCallReadRepository $llmCalls,
    ) {}

    /**
     * @param  string  $criterion  'patch'|'no-regression'|'noise-aware' — не типизировано литералом:
     *                             значение приходит от вызывающего (CLI-опция) непроверенным,
     *                             инвариант держит runtime-guard ниже
     * @param  EvalRun|null  $noisePairBase  нулевая пара (`--noise-pair`), обязательна ⟺ `$criterion`
     *                                       = `noise-aware` — тихого дефолта нет (P2.13 Implementation Rules)
     * @return array{
     *     base_run_id: int, candidate_run_id: int,
     *     subsamples: array<string, array{
     *         base: array{f1: float, row_coverage: float, per_field: array<string, float>, examples: int},
     *         candidate: array{f1: float, row_coverage: float, per_field: array<string, float>, examples: int},
     *         delta: array{f1: float, row_coverage: float, per_field: array<string, float>},
     *     }>,
     *     per_fixture: array<string, float>,
     *     verdict: 'accept'|'reject',
     *     reasons: list<string>,
     *     noise_basis?: array{
     *         pair_base_id: int, pair_candidate_id: int,
     *         subsample_thresholds: array<string, array{f1: float, row_coverage: float}>,
     *         per_fixture_threshold: float, per_fixture_worst_code: string,
     *     },
     *     profile?: array{
     *         base: array{calls: int, prompt_tokens: int, completion_tokens: int, avg_prompt_tokens: float, cost_micros: int},
     *         candidate: array{calls: int, prompt_tokens: int, completion_tokens: int, avg_prompt_tokens: float, cost_micros: int},
     *         output_drop: int, output_drop_noise_threshold: int,
     *     },
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

        $subsamples = [];

        foreach (['tune', 'holdout'] as $subsample) {
            $baseSub = $this->filterBySubsample($baseResults, $holdoutCodes, $subsample);
            $candidateSub = $this->filterBySubsample($candidateResults, $holdoutCodes, $subsample);

            if ($baseSub->isEmpty() || $candidateSub->isEmpty()) {
                throw new RuntimeException(
                    "Подвыборка `{$subsample}` пуста в одном из прогонов (#{$base->id}/#{$candidate->id}) — критерий приёмки не проверить. Прогони `estimate:eval:run --split=all`.",
                );
            }

            $subsamples[$subsample] = $this->aggregateDelta($baseSub, $candidateSub);
        }

        $perFixture = $this->perFixtureDeltas($baseResults, $candidateResults);

        $noiseThresholds = null;
        $noisePairIds = null;
        $profile = null;

        if ($criterion === self::CRITERION_NOISE_AWARE) {
            /** @var EvalRun $noisePairBase */
            /** @var EvalRun $noisePairCandidate */
            $subsamples['overall'] = $this->aggregateDelta($baseResults, $candidateResults);

            [$noiseBaseResults, $noiseCandidateResults] = $this->loadComparablePair($noisePairBase, $noisePairCandidate);
            $noiseThresholds = $this->computeNoiseThresholds($noiseBaseResults, $noiseCandidateResults, $holdoutCodes);
            $noisePairIds = ['base' => $noisePairBase->id, 'candidate' => $noisePairCandidate->id];
            $profile = $this->buildProfile($base, $candidate, $noisePairBase, $noisePairCandidate);
        }

        $verdict = $this->verdict($subsamples, $perFixture, $criterion, $noiseThresholds, $noisePairIds);

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
     * Общая проверка пары прогонов (маршрут, версии промтов, состав эталонов) — используется и для
     * сравниваемой пары, и для нулевой пары `--noise-pair` (P2.13 Implementation Rules: «пара,
     * поданная нулевой, проверяется тем же `assertComparable()`, что и сравниваемые прогоны»).
     *
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
            ->map(fn (EvalResult $result): array => $this->resultRow($result));
    }

    /**
     * @return list<string>
     */
    private function loadHoldoutCodes(): array
    {
        $path = $this->stringValue(config('ai.fixtures.splits_path'), 'ai.fixtures.splits_path');

        if (! is_file($path)) {
            throw new RuntimeException("Файл разбиения не найден: {$path}. Прогони `estimate:eval:run --write-splits`.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Файл разбиения не удалось прочитать: {$path}.");
        }

        $decoded = $this->jsonObject(json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR), 'eval splits');
        $holdout = $decoded['holdout'] ?? [];

        if (! is_array($holdout)) {
            throw new RuntimeException('eval splits holdout должен быть массивом кодов.');
        }

        $codes = [];

        foreach ($holdout as $code) {
            $codes[] = $this->stringValue($code, 'eval splits holdout code');
        }

        return $codes;
    }

    /**
     * @param  Collection<int, ResultRow>  $results
     * @param  list<string>  $holdoutCodes
     * @return Collection<int, ResultRow>
     */
    private function filterBySubsample(Collection $results, array $holdoutCodes, string $subsample): Collection
    {
        return $results->filter(function (array $result) use ($holdoutCodes, $subsample): bool {
            $inHoldout = in_array($result['code'], $holdoutCodes, true);

            return $subsample === 'holdout' ? $inHoldout : ! $inHoldout;
        })->values();
    }

    /**
     * @param  Collection<int, ResultRow>  $results
     * @return SubsampleAggregate
     */
    private function aggregateSubsample(Collection $results): array
    {
        $first = $results->first();

        if ($first === null) {
            throw new RuntimeException('Нельзя агрегировать пустую подвыборку eval.');
        }

        $fields = array_keys($first['per_field']);

        $perField = [];

        foreach ($fields as $field) {
            $perField[$field] = round(
                (float) $results->avg(fn (array $result): float => $result['per_field'][$field]['f1'] ?? 0.0),
                4,
            );
        }

        return [
            'f1' => round((float) $results->avg('f1'), 4),
            'row_coverage' => round((float) $results->avg('row_coverage'), 4),
            'per_field' => $perField,
            'examples' => $results->count(),
        ];
    }

    /**
     * @param  Collection<int, ResultRow>  $baseResults
     * @param  Collection<int, ResultRow>  $candidateResults
     * @return array{
     *     base: array{f1: float, row_coverage: float, per_field: array<string, float>, examples: int},
     *     candidate: array{f1: float, row_coverage: float, per_field: array<string, float>, examples: int},
     *     delta: array{f1: float, row_coverage: float, per_field: array<string, float>},
     * }
     */
    private function aggregateDelta(Collection $baseResults, Collection $candidateResults): array
    {
        $baseAggregate = $this->aggregateSubsample($baseResults);
        $candidateAggregate = $this->aggregateSubsample($candidateResults);

        return [
            'base' => $baseAggregate,
            'candidate' => $candidateAggregate,
            'delta' => [
                'f1' => round($candidateAggregate['f1'] - $baseAggregate['f1'], 4),
                'row_coverage' => round($candidateAggregate['row_coverage'] - $baseAggregate['row_coverage'], 4),
                'per_field' => $this->deltaPerField($baseAggregate['per_field'], $candidateAggregate['per_field']),
            ],
        ];
    }

    /**
     * @param  array<string, float>  $base
     * @param  array<string, float>  $candidate
     * @return array<string, float>
     */
    private function deltaPerField(array $base, array $candidate): array
    {
        $delta = [];

        foreach ($base as $field => $baseValue) {
            $delta[$field] = round(($candidate[$field] ?? 0.0) - $baseValue, 4);
        }

        return $delta;
    }

    /**
     * @param  Collection<int, ResultRow>  $baseResults
     * @param  Collection<int, ResultRow>  $candidateResults
     * @return array<string, float>
     */
    private function perFixtureDeltas(Collection $baseResults, Collection $candidateResults): array
    {
        $baseByCode = $baseResults->keyBy('code');
        $candidateByCode = $candidateResults->keyBy('code');

        $deltas = [];

        foreach ($baseByCode as $code => $baseResult) {
            if (! is_string($code)) {
                throw new RuntimeException('Код eval-эталона должен быть строкой.');
            }

            $candidateResult = $candidateByCode->get($code);

            if ($candidateResult === null) {
                continue;
            }

            $deltas[$code] = round($candidateResult['f1'] - $baseResult['f1'], 4);
        }

        return $deltas;
    }

    /**
     * Пороги режима `noise-aware`: модуль дельты нулевой пары по каждой оси (P2.13 Implementation
     * Rules). Модуль не зависит от того, какой прогон пары назван базой (|a−b| = |b−a|) — второе
     * направление не даёт нового числа, поэтому считается один раз. Per-fixture — ОДНА общая ось
     * (как и старый `PER_FIXTURE_F1_MAX_DROP`, единый для всех эталонов): порог — модуль ХУДШЕЙ
     * (максимальной) дельты пары среди всех эталонов, а не дельта каждого эталона по отдельности
     * (`findings/P2-eval-resolution-2026-08-02.md` §2 — «per-fixture (худший)=0.6131», §3 — все три
     * причины `(г)` M5 сверены с ОДНИМ и тем же числом шума).
     *
     * @param  Collection<int, ResultRow>  $noiseBaseResults
     * @param  Collection<int, ResultRow>  $noiseCandidateResults
     * @param  list<string>  $holdoutCodes
     * @return array{subsamples: array<string, array{f1: float, row_coverage: float}>, per_fixture_threshold: float, per_fixture_worst_code: string}
     */
    private function computeNoiseThresholds(Collection $noiseBaseResults, Collection $noiseCandidateResults, array $holdoutCodes): array
    {
        $subsampleThresholds = [];

        foreach (['tune', 'holdout'] as $subsample) {
            $baseSub = $this->filterBySubsample($noiseBaseResults, $holdoutCodes, $subsample);
            $candidateSub = $this->filterBySubsample($noiseCandidateResults, $holdoutCodes, $subsample);

            if ($baseSub->isEmpty() || $candidateSub->isEmpty()) {
                throw new RuntimeException("Нулевая пара не несёт подвыборку `{$subsample}` — порог не вычислить.");
            }

            $baseAggregate = $this->aggregateSubsample($baseSub);
            $candidateAggregate = $this->aggregateSubsample($candidateSub);

            $subsampleThresholds[$subsample] = [
                'f1' => abs(round($candidateAggregate['f1'] - $baseAggregate['f1'], 4)),
                'row_coverage' => abs(round($candidateAggregate['row_coverage'] - $baseAggregate['row_coverage'], 4)),
            ];
        }

        $perFixtureDeltas = array_map(abs(...), $this->perFixtureDeltas($noiseBaseResults, $noiseCandidateResults));

        if ($perFixtureDeltas === []) {
            throw new RuntimeException('Нулевая пара не несёт ни одного эталона — порог per-fixture не вычислить.');
        }

        $worstCode = array_keys($perFixtureDeltas, max($perFixtureDeltas), true)[0];

        return [
            'subsamples' => $subsampleThresholds,
            'per_fixture_threshold' => $perFixtureDeltas[$worstCode],
            'per_fixture_worst_code' => (string) $worstCode,
        ];
    }

    /**
     * Профиль вызовов `llm_calls` обоих сравниваемых прогонов (не нулевой пары) — П2.13 Scope
     * Included; порог падения вывода вычислен из нулевой пары, но сам блок информационный и на
     * `verdict()` не влияет (Validation #3 item'а: причины отказа — только оси f1/row_coverage).
     *
     * @return array{
     *     base: array{calls: int, prompt_tokens: int, completion_tokens: int, avg_prompt_tokens: float, cost_micros: int},
     *     candidate: array{calls: int, prompt_tokens: int, completion_tokens: int, avg_prompt_tokens: float, cost_micros: int},
     *     output_drop: int, output_drop_noise_threshold: int,
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
     * @return array{calls: int, prompt_tokens: int, completion_tokens: int, avg_prompt_tokens: float, cost_micros: int}
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

    /**
     * @param  Subsamples  $subsamples
     * @param  array<string, float>  $perFixture
     * @param  'patch'|'no-regression'|'noise-aware'  $criterion
     * @param  array{subsamples: array<string, array{f1: float, row_coverage: float}>, per_fixture_threshold: float, per_fixture_worst_code: string}|null  $noiseThresholds
     * @param  array{base: int, candidate: int}|null  $noisePairIds
     * @return array{verdict: 'accept'|'reject', reasons: list<string>}
     */
    private function verdict(array $subsamples, array $perFixture, string $criterion, ?array $noiseThresholds = null, ?array $noisePairIds = null): array
    {
        if ($criterion === self::CRITERION_NOISE_AWARE) {
            /** @var array{subsamples: array<string, array{f1: float, row_coverage: float}>, per_fixture_threshold: float, per_fixture_worst_code: string} $noiseThresholds */
            /** @var array{base: int, candidate: int} $noisePairIds */
            return $this->noiseAwareVerdict($subsamples, $perFixture, $noiseThresholds, $noisePairIds);
        }

        $reasons = [];

        $tuneF1Delta = $subsamples['tune']['delta']['f1'];
        $holdoutF1Delta = $subsamples['holdout']['delta']['f1'];

        if ($criterion === self::CRITERION_PATCH && $tuneF1Delta < self::TUNE_F1_MIN_GAIN) {
            $reasons[] = sprintf('(а) tune.overall.f1 изменился на %.4f — меньше требуемого роста %.3f', $tuneF1Delta, self::TUNE_F1_MIN_GAIN);
        }

        if ($holdoutF1Delta < -self::HOLDOUT_F1_MAX_DROP) {
            $reasons[] = sprintf('(б) holdout.overall.f1 упал на %.4f — больше допустимых %.3f', -$holdoutF1Delta, self::HOLDOUT_F1_MAX_DROP);
        }

        foreach (['tune', 'holdout'] as $subsample) {
            $rowCoverageDelta = $subsamples[$subsample]['delta']['row_coverage'];

            if ($rowCoverageDelta < -self::ROW_COVERAGE_MAX_DROP) {
                $reasons[] = sprintf('(в) %s.row_coverage упал на %.4f — больше допустимых %.3f', $subsample, -$rowCoverageDelta, self::ROW_COVERAGE_MAX_DROP);
            }
        }

        foreach ($perFixture as $code => $delta) {
            if ($delta < -self::PER_FIXTURE_F1_MAX_DROP) {
                $reasons[] = sprintf('(г) эталон %s потерял %.4f f1 — больше допустимых %.3f', $code, -$delta, self::PER_FIXTURE_F1_MAX_DROP);
            }
        }

        return ['verdict' => $reasons === [] ? 'accept' : 'reject', 'reasons' => $reasons];
    }

    /**
     * Вердикт `noise-aware` — те же оси (б)–(г), что и `verdict()`, но порог каждой оси берётся из
     * `$noiseThresholds` (нулевая пара), а не из констант; граница включена (дельта = порогу → не
     * причина отказа, P2.13 Implementation Rules).
     *
     * @param  Subsamples  $subsamples
     * @param  array<string, float>  $perFixture
     * @param  array{subsamples: array<string, array{f1: float, row_coverage: float}>, per_fixture_threshold: float, per_fixture_worst_code: string}  $noiseThresholds
     * @param  array{base: int, candidate: int}  $noisePairIds
     * @return array{verdict: 'accept'|'reject', reasons: list<string>}
     */
    private function noiseAwareVerdict(array $subsamples, array $perFixture, array $noiseThresholds, array $noisePairIds): array
    {
        $reasons = [];
        $pairSuffix = sprintf('шум пары #%d/#%d', $noisePairIds['base'], $noisePairIds['candidate']);

        $holdoutF1Delta = $subsamples['holdout']['delta']['f1'];
        $holdoutF1Threshold = $noiseThresholds['subsamples']['holdout']['f1'];

        if ($holdoutF1Delta < -$holdoutF1Threshold) {
            $reasons[] = sprintf('(б) holdout.overall.f1 упал на %.4f — больше порога %.4f (%s)', -$holdoutF1Delta, $holdoutF1Threshold, $pairSuffix);
        }

        foreach (['tune', 'holdout'] as $subsample) {
            $rowCoverageDelta = $subsamples[$subsample]['delta']['row_coverage'];
            $threshold = $noiseThresholds['subsamples'][$subsample]['row_coverage'];

            if ($rowCoverageDelta < -$threshold) {
                $reasons[] = sprintf('(в) %s.row_coverage упал на %.4f — больше порога %.4f (%s)', $subsample, -$rowCoverageDelta, $threshold, $pairSuffix);
            }
        }

        $perFixtureThreshold = $noiseThresholds['per_fixture_threshold'];

        foreach ($perFixture as $code => $delta) {
            if ($delta < -$perFixtureThreshold) {
                $reasons[] = sprintf('(г) эталон %s потерял %.4f f1 — больше порога %.4f (%s, худший эталон пары — %s)', $code, -$delta, $perFixtureThreshold, $pairSuffix, $noiseThresholds['per_fixture_worst_code']);
            }
        }

        return ['verdict' => $reasons === [] ? 'accept' : 'reject', 'reasons' => $reasons];
    }

    /**
     * @return ResultRow
     */
    private function resultRow(EvalResult $result): array
    {
        $code = $result->datasetExample?->fixture?->code;

        if (! is_string($code) || $code === '') {
            throw new RuntimeException("EvalResult #{$result->id} не имеет кода fixture.");
        }

        $details = $this->jsonObject($result->details?->all() ?? [], "EvalResult #{$result->id} details");

        return [
            'code' => $code,
            'f1' => $this->floatValue($result->value, "EvalResult #{$result->id} f1"),
            'row_coverage' => $this->floatValue($details['row_coverage'] ?? 0.0, "EvalResult #{$result->id} row_coverage"),
            'per_field' => $this->perFieldMetrics($details['per_field'] ?? []),
        ];
    }

    /**
     * @return PerFieldMetrics
     */
    private function perFieldMetrics(mixed $value): array
    {
        $fields = $this->jsonObject($value, 'EvalResult per_field');
        $metrics = [];

        foreach ($fields as $field => $metric) {
            $metricData = $this->jsonObject($metric, "EvalResult per_field {$field}");
            $metrics[$field] = ['f1' => $this->floatValue($metricData['f1'] ?? null, "EvalResult per_field {$field}.f1")];
        }

        return $metrics;
    }

    private function floatValue(mixed $value, string $context): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new RuntimeException("{$context} должен быть числом.");
    }

    private function stringValue(mixed $value, string $context): string
    {
        if (! is_string($value)) {
            throw new RuntimeException("{$context} должен быть строкой.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(mixed $value, string $context): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException("{$context} должен быть JSON-объектом.");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
