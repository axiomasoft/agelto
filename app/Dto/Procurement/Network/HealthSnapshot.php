<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Enums\Procurement\Network\CircuitState;
use App\Enums\Procurement\Network\HealthScope;
use App\Enums\Procurement\Network\TargetAvailability;

final readonly class HealthSnapshot
{
    public function __construct(
        public HealthScope $scope, public string $id, public CircuitState $state, public int $epoch,
        public int $consecutive, public int $openings, public ?string $reason,
        public ?\DateTimeImmutable $openUntil, public ?\DateTimeImmutable $stableSince,
        public ?string $probeToken, public ?\DateTimeImmutable $probeUntil,
        public ?TargetAvailability $availability = null,
    ) {}

    public function key(): string
    {
        return $this->scope->value.':'.$this->id;
    }
}
