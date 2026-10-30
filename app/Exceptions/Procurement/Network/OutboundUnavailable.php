<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Network;

use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\NetworkDeferralReason;

/** A dependency/control outcome at a surviving legacy domain boundary, never an item defect. */
final class OutboundUnavailable extends \RuntimeException
{
    public function __construct(public readonly TransportDeferred|TransportBlocked $outcome)
    {
        parent::__construct('Outbound dependency unavailable: '.$outcome->reason->value);
    }

    public static function dependency(int $retrySeconds = 30): self
    {
        return new self(new TransportDeferred(NetworkDeferralReason::DependencyUnavailable,
            new RetryAt(new \DateTimeImmutable('+'.$retrySeconds.' seconds'))));
    }
}
