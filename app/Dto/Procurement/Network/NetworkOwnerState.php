<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Enums\Procurement\Network\DemandPurpose;

/** Locked owner facts; deadline uses the database clock, never PHP wall time. */
final readonly class NetworkOwnerState
{
    /** @param array<string, int> $versions Pinned input/ancestor guards from the locked Process prefix. */
    public function __construct(public DemandPurpose $purpose, public ?string $probeToken, public ?\DateTimeImmutable $deadlineAt, public array $versions = [], public ?int $platformId = null) {}
}
