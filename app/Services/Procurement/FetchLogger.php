<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\Crawler\CrawlStage;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Run;

final readonly class FetchLogger
{
    public function log(CrawlStage $stage, ?Run $run, ?int $procurementId, array $attributes): Fetch
    {
        if ($stage === CrawlStage::Listing && $run === null) {
            throw new \InvalidArgumentException('Listing fetch requires crawler run.');
        }

        return Fetch::create([...$attributes, 'stage' => $stage->value, 'crawler_run_id' => $run?->id, 'procurement_id' => $procurementId, 'fetched_at' => now()]);
    }
}
