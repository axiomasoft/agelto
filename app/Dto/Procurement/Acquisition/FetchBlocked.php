<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Enums\Procurement\Network\NetworkBlockedReason;

final readonly class FetchBlocked implements DetailFetchResult, ListingFetchResult
{
    public function __construct(public NetworkBlockedReason $reason) {}
}
