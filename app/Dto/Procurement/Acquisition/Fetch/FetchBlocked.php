<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Fetch;

use App\Dto\Procurement\Acquisition\Detail\DetailFetchResult;
use App\Dto\Procurement\Acquisition\Listing\ListingFetchResult;
use App\Enums\Procurement\Network\NetworkBlockedReason;

final readonly class FetchBlocked implements DetailFetchResult, ListingFetchResult
{
    public function __construct(public NetworkBlockedReason $reason) {}
}
