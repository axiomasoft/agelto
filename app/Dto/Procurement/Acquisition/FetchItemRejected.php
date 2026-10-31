<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Enums\Procurement\Process\ItemFailureReason;

final readonly class FetchItemRejected implements DetailFetchResult, ListingFetchResult
{
    public function __construct(public ItemFailureReason $reason, public ResponseAssessment $assessment) {}
}
