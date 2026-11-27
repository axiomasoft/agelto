<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Execution;

use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;

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
