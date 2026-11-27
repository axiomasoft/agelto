<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Fetch;

use App\Dto\Procurement\Acquisition\Detail\DetailFetchResult;
use App\Dto\Procurement\Acquisition\Listing\ListingFetchResult;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Network\NetworkDeferralReason;

final readonly class FetchDeferred implements DetailFetchResult, ListingFetchResult
{
    public function __construct(public NetworkDeferralReason $reason, public RetryAt $retryAt) {}
}
