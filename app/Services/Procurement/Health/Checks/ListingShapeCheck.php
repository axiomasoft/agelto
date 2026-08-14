<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health\Checks;

use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\Health\Contracts\HealthCheck;
use App\Services\Procurement\Health\HealthCheckResultFactory;
use Carbon\CarbonInterface;

final readonly class ListingShapeCheck implements HealthCheck
{
    public function __construct(private HealthCheckResultFactory $results) {}

    public function kind(): PlatformCheckKind
    {
        return PlatformCheckKind::ListingShape;
    }

    public function run(Platform $platform, HealthBaseline $baseline, CarbonInterface $from, CarbonInterface $to): CheckResult
    {
        $thresholds = $baseline->thresholds[$this->kind()->value] ?? [];
        $rowErrorShareBadThreshold = $thresholds['row_error_share_bad_threshold'] ?? null;

        if (! is_numeric($rowErrorShareBadThreshold)) {
            throw new \LogicException('В эталоне отсутствует порог доли ошибок разбора листинга.');
        }

        $fetches = Fetch::query()
            ->where('stage', CrawlStage::Listing->value)
            ->whereBetween('fetched_at', [$from, $to])
            ->whereHas('run.crawler.registry', fn ($query) => $query->where('platform_id', $platform->id))
            ->get(['id', 'rows_total', 'rows_parsed', 'rows_errors', 'http_status']);

        $parseSamples = $fetches->filter(fn (Fetch $fetch): bool => self::nullableIntAttribute(fetch: $fetch, attribute: 'rows_total') !== null
            && self::nullableIntAttribute(fetch: $fetch, attribute: 'rows_parsed') !== null
            && self::nullableIntAttribute(fetch: $fetch, attribute: 'rows_errors') !== null);
        $parseSample = $parseSamples->count();
        $parseFailures = $parseSamples->filter(function (Fetch $fetch) use ($rowErrorShareBadThreshold): bool {
            $rowsTotal = self::intAttribute(fetch: $fetch, attribute: 'rows_total');
            $rowsParsed = self::intAttribute(fetch: $fetch, attribute: 'rows_parsed');
            $rowsErrors = self::intAttribute(fetch: $fetch, attribute: 'rows_errors');
            $rowErrorShare = $rowsTotal === 0 ? 0.0 : $rowsErrors / $rowsTotal;

            return $rowsTotal > 0
                && ($rowsParsed === 0 || $rowErrorShare >= (float) $rowErrorShareBadThreshold);
        })->count();
        $maxRowErrorShare = $parseSamples
            ->filter(fn (Fetch $fetch): bool => self::intAttribute(fetch: $fetch, attribute: 'rows_total') > 0)
            ->map(fn (Fetch $fetch): float => self::intAttribute(fetch: $fetch, attribute: 'rows_errors') / self::intAttribute(fetch: $fetch, attribute: 'rows_total'))
            ->max() ?? 0.0;
        $nonOk = $fetches->filter(fn (Fetch $fetch): bool => self::nullableIntAttribute(fetch: $fetch, attribute: 'http_status') !== 200)->count();
        $parseFailureShare = $parseSample === 0 ? 0.0 : $parseFailures / $parseSample;
        $nonOkShare = $fetches->isEmpty() ? 0.0 : $nonOk / $fetches->count();

        return $this->results->make(
            kind: $this->kind(),
            baseline: $baseline,
            measured: [
                'sample' => $fetches->count(),
                'parse_sample' => $parseSample,
                'unknown_parse_metrics' => $fetches->count() - $parseSample,
                'parse_failure_bad' => $parseFailures,
                'parse_failure_share' => $parseFailureShare,
                'max_row_error_share' => $maxRowErrorShare,
                'row_error_share_denominator' => 'rows_total',
                'row_error_share_bad_threshold' => (float) $rowErrorShareBadThreshold,
                'non_ok_bad' => $nonOk,
                'non_ok_share' => $nonOkShare,
            ],
            sampleSize: $parseSample,
            badShares: [$parseFailureShare, $maxRowErrorShare, $nonOkShare],
        );
    }

    private static function intAttribute(Fetch $fetch, string $attribute): int
    {
        $value = self::nullableIntAttribute(fetch: $fetch, attribute: $attribute);

        if ($value === null) {
            throw new \LogicException("Fetch {$attribute} должен быть целым числом.");
        }

        return $value;
    }

    private static function nullableIntAttribute(Fetch $fetch, string $attribute): ?int
    {
        $value = $fetch->getAttribute($attribute);

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new \LogicException("Fetch {$attribute} должен быть целым числом.");
        }

        return $value;
    }
}
