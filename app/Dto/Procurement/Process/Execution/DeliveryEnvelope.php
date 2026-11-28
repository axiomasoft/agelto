<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Execution;

use App\Dto\Procurement\Process\Primitives\DeliveryGeneration;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;

/**
 * Queue envelope одной delivery: только scalar identity — work, dispatch generation,
 * message и версия схемы. Ни моделей, ни credentials, ни payload. Неподдерживаемая версия схемы не
 * исполняется: runner открывает видимый incident, а работу восстанавливают durable scanners.
 */
final readonly class DeliveryEnvelope
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public WorkId $workId,
        public DeliveryGeneration $dispatchGeneration,
        public ProcessUuid $messageId,
        public int $queueSchemaVersion,
    ) {}

    public static function fromScalars(string $workId, int $dispatchGeneration, string $messageId, int $queueSchemaVersion): self
    {
        return new self(new WorkId(new ProcessUuid($workId)), new DeliveryGeneration($dispatchGeneration), new ProcessUuid($messageId), $queueSchemaVersion);
    }

    public function supported(): bool
    {
        return $this->queueSchemaVersion === self::SCHEMA_VERSION;
    }
}
