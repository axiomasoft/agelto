<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

final readonly class StoreWorkData
{
    public function __construct(
        public WorkId $id,
        public WorkInput $input,
        public int $inputRevision,
        public ?ProcessUuid $intentId,
        public string $cohortKey,
        public ?int $platformId,
        public ?ProcessUuid $originRunId,
        public int $priority,
        public ProcessUuid $eventId,
        public ProcessUuid $projectionMessageId,
        public ?WorkIdentity $identity = null,
    ) {}
}
