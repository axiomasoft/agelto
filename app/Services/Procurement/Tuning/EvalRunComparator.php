<?php

declare(strict_types=1);

namespace App\Services\Procurement\Tuning;

use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Критерий приёмки патча промта между двумя `EvalRun` (P7.5, D63 + правило отбора D119/D120):
 * подвыборки `tune`/`holdout` вычисляются из `estimates/eval-splits.json` по членству в `holdout`
 * (та же семантика, что `estimate:eval:run --split`) — сравниваемые прогоны предполагаются прогнанными
 * `--split=all`, иначе одна из подвыборок останется пустой и `compare()` откажется считать критерий.
 * Числа для порогов (а)–(г) — макро-усреднение per-example `f1`/`row_coverage` внутри подвыборки,
 * тот же приём, что `EvalRunCommand::aggregate()` для прогона целиком.
 */
final readonly class EvalRunComparator
{
    private const float TUNE_F1_MIN_GAIN = 0.01;

    private const float HOLDOUT_F1_MAX_DROP = 0.005;

    private const float ROW_COVERAGE_MAX_DROP = 0.005;

    private const float PER_FIXTURE_F1_MAX_DROP = 0.05;

    /**
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
     * }
     */
    public function compare(EvalRun $base, EvalRun $candidate): array
    {
        $this->assertComparable($base, $candidate);

        $baseResults = $this->loadResults($base);
        $candidateResults = $this->loadResults($candidate);

        $baseCodes = $baseResults->pluck('code')->sort()->values()->all();
        $candidateCodes = $candidateResults->pluck('code')->sort()->values()->all();

        if ($baseCodes !== $candidateCodes) {
            throw new RuntimeException(
                "Прогоны #{$base->id}/#{$candidate->id} несравнимы: разный состав эталонов подвыборки.",
            );
        }

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

            $baseAggregate = $this->aggregateSubsample($baseSub);
            $candidateAggregate = $this->aggregateSubsample($candidateSub);

            $subsamples[$subsample] = [
                'base' => $baseAggregate,
                'candidate' => $candidateAggregate,
                'delta' => [
                    'f1' => round($candidateAggregate['f1'] - $baseAggregate['f1'], 4),
                    'row_coverage' => round($candidateAggregate['row_coverage'] - $baseAggregate['row_coverage'], 4),
                    'per_field' => $this->deltaPerField($baseAggregate['per_field'], $candidateAggregate['per_field']),
                ],
            ];
        }

        $perFixture = $this->perFixtureDeltas($baseResults, $candidateResults);
        $verdict = $this->verdict($subsamples, $perFixture);

        return [
            'base_run_id' => $base->id,
            'candidate_run_id' => $candidate->id,
            'subsamples' => $subsamples,
            'per_fixture' => $perFixture,
            'verdict' => $verdict['verdict'],
            'reasons' => $verdict['reasons'],
        ];
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
     * @return Collection<int, array<string, mixed>>
     */
    private function loadResults(EvalRun $run): Collection
    {
        return EvalResult::query()
            ->where('eval_run_id', $run->id)
            ->where('metric', 'f1')
            ->with('datasetExample.fixture')
            ->get()
            ->map(fn (EvalResult $result): array => [
                'code' => $result->datasetExample?->fixture?->code,
                'f1' => (float) $result->value,
                'row_coverage' => (float) ($result->details['row_coverage'] ?? 0.0),
                'per_field' => (array) ($result->details['per_field'] ?? []),
            ]);
    }

    /**
     * @return list<string>
     */
    private function loadHoldoutCodes(): array
    {
        $path = (string) config('ai.fixtures.splits_path');

        if (! is_file($path)) {
            throw new RuntimeException("Файл разбиения не найден: {$path}. Прогони `estimate:eval:run --write-splits`.");
        }

        $decoded = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        return array_values((array) ($decoded['holdout'] ?? []));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $results
     * @param  list<string>  $holdoutCodes
     * @return Collection<int, array<string, mixed>>
     */
    private function filterBySubsample(Collection $results, array $holdoutCodes, string $subsample): Collection
    {
        return $results->filter(function (array $result) use ($holdoutCodes, $subsample): bool {
            $inHoldout = in_array($result['code'], $holdoutCodes, true);

            return $subsample === 'holdout' ? $inHoldout : ! $inHoldout;
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $results
     * @return array{f1: float, row_coverage: float, per_field: array<string, float>, examples: int}
     */
    private function aggregateSubsample(Collection $results): array
    {
        $fields = array_keys($results->first()['per_field'] ?? []);

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
     * @param  Collection<int, array<string, mixed>>  $baseResults
     * @param  Collection<int, array<string, mixed>>  $candidateResults
     * @return array<string, float>
     */
    private function perFixtureDeltas(Collection $baseResults, Collection $candidateResults): array
    {
        $baseByCode = $baseResults->keyBy('code');
        $candidateByCode = $candidateResults->keyBy('code');

        $deltas = [];

        foreach ($baseByCode as $code => $baseResult) {
            $candidateResult = $candidateByCode->get($code);

            if ($candidateResult === null) {
                continue;
            }

            $deltas[$code] = round($candidateResult['f1'] - $baseResult['f1'], 4);
        }

        return $deltas;
    }

    /**
     * @param  array<string, array{base: array<string, mixed>, candidate: array<string, mixed>, delta: array<string, mixed>}>  $subsamples
     * @param  array<string, float>  $perFixture
     * @return array{verdict: 'accept'|'reject', reasons: list<string>}
     */
    private function verdict(array $subsamples, array $perFixture): array
    {
        $reasons = [];

        $tuneF1Delta = $subsamples['tune']['delta']['f1'];
        $holdoutF1Delta = $subsamples['holdout']['delta']['f1'];

        if ($tuneF1Delta < self::TUNE_F1_MIN_GAIN) {
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
}
