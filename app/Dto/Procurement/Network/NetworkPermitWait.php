<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\NetworkDeferralReason;

/** Cooperative polling only while the original bounded registration window remains live. */
final readonly class NetworkPermitWait
{
    public function __construct(public RetryAt $retryAt, public NetworkDeferralReason $reason) {}
}
