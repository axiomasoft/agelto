<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;

/** Bounded full validators under system+monitoring; never ingest or advance acquisition state. */
interface DiagnosticSourceMethod
{
    public function diagnoseListing(Crawler $crawler, ListingCursor $cursor, float $deadlineAt): ListingBatch;

    public function diagnoseDetails(string $registryNumber, Registry $registry, float $deadlineAt): ProcurementDetails;
}
