<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\Crawler\CrawlStage;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Run;
use App\Repositories\Procurement\Crawler\FetchStoreRepository;

final readonly class FetchLogger
{
    public function __construct(private FetchStoreRepository $fetches) {}

    /** @param array<string, mixed> $attributes */
    public function log(CrawlStage $stage, ?Run $run, ?int $procurementId, array $attributes): Fetch
    {
        if ($stage === CrawlStage::Listing && $run === null) {
            throw new \InvalidArgumentException('Listing fetch requires crawler run.');
        }

        return $this->fetches->create([
            ...$attributes,
            'stage' => $stage->value,
            'crawler_run_id' => $run?->id,
            'procurement_id' => $procurementId,
            'fetched_at' => now(),
        ]);
    }
}
