<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\NetworkDeferralReason;

final readonly class FetchDeferred implements DetailFetchResult, ListingFetchResult
{
    public function __construct(public NetworkDeferralReason $reason, public RetryAt $retryAt) {}
}
