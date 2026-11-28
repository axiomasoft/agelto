<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\Outcome;

use App\Dto\Procurement\Network\Http\FinalHttpResponseEvidence;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Network\NetworkDeferralReason;

/** Зависимость временно недоступна: осмысленный RetryAt, без расхода item budget. */
final readonly class TransportDeferred implements TransportOutcome
{
    public function __construct(public NetworkDeferralReason $reason, public RetryAt $retryAt,
        public ?FinalHttpResponseEvidence $finalResponse = null,
        public ?ProcessUuid $lastRequestId = null) {}
}
