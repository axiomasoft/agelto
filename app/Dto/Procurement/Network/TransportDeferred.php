<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\NetworkDeferralReason;

/** Зависимость временно недоступна: осмысленный RetryAt, без расхода item budget. */
final readonly class TransportDeferred implements TransportOutcome
{
    public function __construct(public NetworkDeferralReason $reason, public RetryAt $retryAt,
        public ?FinalHttpResponseEvidence $finalResponse = null,
        public ?ProcessUuid $lastRequestId = null) {}
}
