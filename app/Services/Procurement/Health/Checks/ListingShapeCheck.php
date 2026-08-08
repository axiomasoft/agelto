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
        $fetches = Fetch::query()
            ->where('stage', CrawlStage::Listing->value)
            ->whereBetween('fetched_at', [$from, $to])
            ->whereHas('run.crawler.registry', fn ($query) => $query->where('platform_id', $platform->id))
            ->get(['id', 'rows_total', 'rows_new', 'rows_updated', 'http_status']);

        $sample = $fetches->count();
        $emptyIngest = $fetches->filter(fn (Fetch $fetch): bool => (int) $fetch->getAttribute('rows_total') > 0
            && (int) $fetch->getAttribute('rows_new') + (int) $fetch->getAttribute('rows_updated') === 0)->count();
        $nonOk = $fetches->filter(fn (Fetch $fetch): bool => (int) $fetch->getAttribute('http_status') !== 200)->count();
        $emptyIngestShare = $sample === 0 ? 0 : $emptyIngest / $sample;
        $nonOkShare = $sample === 0 ? 0 : $nonOk / $sample;

        return $this->results->make(
            kind: $this->kind(),
            baseline: $baseline,
            measured: [
                'sample' => $sample,
                'empty_ingest_bad' => $emptyIngest,
                'empty_ingest_share' => $emptyIngestShare,
                'non_ok_bad' => $nonOk,
                'non_ok_share' => $nonOkShare,
            ],
            sampleSize: $sample,
            badShares: [$emptyIngestShare, $nonOkShare],
        );
    }
}
