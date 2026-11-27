<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Transport;

use Proxyma\Domain\Admission\ReservationInput;
use Proxyma\Domain\Execution\ScheduledPermit;
use Proxyma\Domain\Execution\SealedWire;

final readonly class PreparedProviderNetwork
{
    public function __construct(public ReservationInput $input, public ScheduledPermit $permit, public SealedWire $wire) {}

    public function __serialize(): array
    {
        throw new \LogicException('Prepared provider authority cannot be serialized.');
    }
}
