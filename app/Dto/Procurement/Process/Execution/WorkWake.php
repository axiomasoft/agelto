<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Execution;

use App\Dto\Procurement\Process\Primitives\DeliveryGeneration;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;

/**
 * Decoded typed payload work_wake: ровно то, что relay кладёт в ExecuteWorkJob.
 * Версия queue schema хранится как есть: неподдерживаемая версия отвергается relay, а не codec.
 */
final readonly class WorkWake
{
    public function __construct(
        public WorkId $workId,
        public DeliveryGeneration $dispatchGeneration,
        public ProcessUuid $messageId,
        public int $queueSchemaVersion,
        public StageCode $stage,
    ) {}

    public function envelope(): DeliveryEnvelope
    {
        return new DeliveryEnvelope($this->workId, $this->dispatchGeneration, $this->messageId, $this->queueSchemaVersion);
    }
}
