<?php

declare(strict_types=1);

namespace App\Services\Procurement\Tuning;

use Illuminate\Support\Collection;
use RuntimeException;

/**
 * @phpstan-type PerFieldMetrics array<string, array{f1: float}>
 * @phpstan-type ResultRow array{code: string, f1: float, row_coverage: float, per_field: PerFieldMetrics}
 * @phpstan-type SubsampleAggregate array{f1: float, row_coverage: float, per_field: array<string, float>, examples: int}
 * @phpstan-type SubsampleDelta array{base: SubsampleAggregate, candidate: SubsampleAggregate, delta: array{f1: float, row_coverage: float, per_field: array<string, float>}}
 * @phpstan-type Subsamples array<string, SubsampleDelta>
 */
final readonly class EvalGateEvaluator
{
    private const float TUNE_F1_MIN_GAIN = 0.01;

    private const float HOLDOUT_F1_MAX_DROP = 0.005;

    private const float ROW_COVERAGE_MAX_DROP = 0.005;

    private const float PER_FIXTURE_F1_MAX_DROP = 0.05;

    /**
     * @param  Collection<int, ResultRow>  $baseResults
     * @param  Collection<int, ResultRow>  $candidateResults
     * @param  list<string>  $holdoutCodes
     * @return Subsamples
     */
    public function buildSubsamples(
        Collection $baseResults,
        Collection $candidateResults,
        array $holdoutCodes,
        int $baseId,
        int $candidateId,
        bool $includeOverall,
    ): array {
        $subsamples = [];

        foreach (['tune', 'holdout'] as $subsample) {
            $baseSub = $this->filterBySubsample($baseResults, $holdoutCodes, $subsample);
            $candidateSub = $this->filterBySubsample($candidateResults, $holdoutCodes, $subsample);

            if ($baseSub->isEmpty() || $candidateSub->isEmpty()) {
                throw new RuntimeException(
                    "Подвыборка `{$subsample}` пуста в одном из прогонов (#{$baseId}/#{$candidateId}) — критерий приёмки не проверить. Прогони `estimate:eval:run --split=all`.",
                );
            }

            $subsamples[$subsample] = $this->aggregateDelta($baseSub, $candidateSub);
        }

        if ($includeOverall) {
            $subsamples['overall'] = $this->aggregateDelta($baseResults, $candidateResults);
        }

        return $subsamples;
    }

    /**
     * @param  Collection<int, ResultRow>  $baseResults
     * @param  Collection<int, ResultRow>  $candidateResults
     * @return array<string, float>
     */
    public function perFixtureDeltas(Collection $baseResults, Collection $candidateResults): array
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
     * @param  Collection<int, ResultRow>  $noiseBaseResults
     * @param  Collection<int, ResultRow>  $noiseCandidateResults
     * @param  list<string>  $holdoutCodes
     * @return array{subsamples: array<string, array{f1: float, row_coverage: float}>, per_fixture_threshold: float, per_fixture_worst_code: string}
     */
    public function computeNoiseThresholds(Collection $noiseBaseResults, Collection $noiseCandidateResults, array $holdoutCodes): array
    {
        $subsampleThresholds = [];

        foreach (['tune', 'holdout'] as $subsample) {
            $baseSub = $this->filterBySubsample($noiseBaseResults, $holdoutCodes, $subsample);
            $candidateSub = $this->filterBySubsample($noiseCandidateResults, $holdoutCodes, $subsample);

            if ($baseSub->isEmpty() || $candidateSub->isEmpty()) {
                throw new RuntimeException("Нулевая пара не несёт подвыборку `{$subsample}` — порог не вычислить.");
            }

            $delta = $this->aggregateDelta($baseSub, $candidateSub)['delta'];

            $subsampleThresholds[$subsample] = [
                'f1' => abs($delta['f1']),
                'row_coverage' => abs($delta['row_coverage']),
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
     * @param  Subsamples  $subsamples
     * @param  array<string, float>  $perFixture
     * @param  'patch'|'no-regression'|'noise-aware'  $criterion
     * @param  array{subsamples: array<string, array{f1: float, row_coverage: float}>, per_fixture_threshold: float, per_fixture_worst_code: string}|null  $noiseThresholds
     * @param  array{base: int, candidate: int}|null  $noisePairIds
     * @return array{verdict: 'accept'|'reject', reasons: list<string>}
     */
    public function evaluateGates(
        array $subsamples,
        array $perFixture,
        string $criterion,
        ?array $noiseThresholds = null,
        ?array $noisePairIds = null,
    ): array {
        if ($criterion === EvalRunComparator::CRITERION_NOISE_AWARE) {
            /** @var array{subsamples: array<string, array{f1: float, row_coverage: float}>, per_fixture_threshold: float, per_fixture_worst_code: string} $noiseThresholds */
            /** @var array{base: int, candidate: int} $noisePairIds */
            return $this->evaluateNoiseAwareGates($subsamples, $perFixture, $noiseThresholds, $noisePairIds);
        }

        $reasons = [
            ...$this->evaluateSoftGates($subsamples, $criterion),
            ...$this->evaluateHardGates($subsamples),
            ...$this->buildRegressionBlockers($perFixture, self::PER_FIXTURE_F1_MAX_DROP),
        ];

        return ['verdict' => $reasons === [] ? 'accept' : 'reject', 'reasons' => $reasons];
    }

    /**
     * @param  array<mixed>  $rawDetails
     * @return ResultRow
     */
    public function parseResultRow(int $resultId, ?string $code, mixed $value, array $rawDetails): array
    {
        if (! is_string($code) || $code === '') {
            throw new RuntimeException("EvalResult #{$resultId} не имеет кода fixture.");
        }

        $details = $this->jsonObject($rawDetails, "EvalResult #{$resultId} details");

        return [
            'code' => $code,
            'f1' => $this->floatValue($value, "EvalResult #{$resultId} f1"),
            'row_coverage' => $this->floatValue($details['row_coverage'] ?? 0.0, "EvalResult #{$resultId} row_coverage"),
            'per_field' => $this->perFieldMetrics($details['per_field'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonObject(mixed $value, string $context): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException("{$context} должен быть JSON-объектом.");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    public function stringValue(mixed $value, string $context): string
    {
        if (! is_string($value)) {
            throw new RuntimeException("{$context} должен быть строкой.");
        }

        return $value;
    }

    /**
     * @param  Subsamples  $subsamples
     * @return list<string>
     */
    private function evaluateSoftGates(array $subsamples, string $criterion): array
    {
        if ($criterion !== EvalRunComparator::CRITERION_PATCH) {
            return [];
        }

        $tuneF1Delta = $subsamples['tune']['delta']['f1'];

        if ($tuneF1Delta < self::TUNE_F1_MIN_GAIN) {
            return [sprintf('(а) tune.overall.f1 изменился на %.4f — меньше требуемого роста %.3f', $tuneF1Delta, self::TUNE_F1_MIN_GAIN)];
        }

        return [];
    }

    /**
     * @param  Subsamples  $subsamples
     * @return list<string>
     */
    private function evaluateHardGates(array $subsamples): array
    {
        $reasons = [];
        $holdoutF1Delta = $subsamples['holdout']['delta']['f1'];

        if ($holdoutF1Delta < -self::HOLDOUT_F1_MAX_DROP) {
            $reasons[] = sprintf('(б) holdout.overall.f1 упал на %.4f — больше допустимых %.3f', -$holdoutF1Delta, self::HOLDOUT_F1_MAX_DROP);
        }

        foreach (['tune', 'holdout'] as $subsample) {
            $rowCoverageDelta = $subsamples[$subsample]['delta']['row_coverage'];

            if ($rowCoverageDelta < -self::ROW_COVERAGE_MAX_DROP) {
                $reasons[] = sprintf('(в) %s.row_coverage упал на %.4f — больше допустимых %.3f', $subsample, -$rowCoverageDelta, self::ROW_COVERAGE_MAX_DROP);
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string, float>  $perFixture
     * @return list<string>
     */
    private function buildRegressionBlockers(array $perFixture, float $maxDrop): array
    {
        $reasons = [];

        foreach ($perFixture as $code => $delta) {
            if ($delta < -$maxDrop) {
                $reasons[] = sprintf('(г) эталон %s потерял %.4f f1 — больше допустимых %.3f', $code, -$delta, $maxDrop);
            }
        }

        return $reasons;
    }

    /**
     * @param  Subsamples  $subsamples
     * @param  array<string, float>  $perFixture
     * @param  array{subsamples: array<string, array{f1: float, row_coverage: float}>, per_fixture_threshold: float, per_fixture_worst_code: string}  $noiseThresholds
     * @param  array{base: int, candidate: int}  $noisePairIds
     * @return array{verdict: 'accept'|'reject', reasons: list<string>}
     */
    private function evaluateNoiseAwareGates(
        array $subsamples,
        array $perFixture,
        array $noiseThresholds,
        array $noisePairIds,
    ): array {
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
     * @return SubsampleDelta
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
}
