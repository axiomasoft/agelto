<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\DemandPurpose;

/** Locked owner facts; deadline uses the database clock, never PHP wall time. */
final readonly class NetworkOwnerState
{
    /** @param array<string, int> $versions Pinned input/ancestor guards from the locked Process prefix. */
    public function __construct(public DemandPurpose $purpose, public ?string $probeToken, public ?\DateTimeImmutable $deadlineAt, public array $versions = [], public ?int $platformId = null, public ?ProviderKey $providerKey = null,
        public ?ProcessUuid $operationId = null,
        public ?ProcessUuid $adapterHealthId = null, public ?int $adapterHealthEpoch = null)
    {
        if (($adapterHealthId === null) !== ($adapterHealthEpoch === null) || ($adapterHealthEpoch !== null && $adapterHealthEpoch < 1)) {
            throw new \InvalidArgumentException('Invalid adapter owner attachment.');
        }
        if ($platformId !== null && $providerKey !== null) {
            throw new \InvalidArgumentException('Outbound owner must have one identity.');
        }
    }
}
