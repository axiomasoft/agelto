<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Governance;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Governance\ActorKind;

final readonly class TransitionData
{
    /** @param array<string, int|string> $details Redacted audit fields */
    public function __construct(
        public ProcessUuid $eventId,
        public ProcessUuid $projectionMessageId,
        public ProcessUuid $entityId,
        public int $entitySeq,
        public ?string $previousState,
        public string $newState,
        public ?string $reasonCode,
        public ActorKind $actor,
        public ?int $generation = null,
        public ?int $actorId = null,
        public array $details = [],
    ) {}
}
