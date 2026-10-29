<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Enums\Procurement\Network\CircuitState;
use App\Enums\Procurement\Network\TargetAvailability;

final readonly class HealthMutation
{
    public function __construct(
        public CircuitState $state, public int $epoch, public int $consecutive, public int $openings,
        public ?string $reason, public ?\DateTimeImmutable $openUntil, public ?\DateTimeImmutable $stableSince,
        public ?TargetAvailability $availability,
    ) {}
}
