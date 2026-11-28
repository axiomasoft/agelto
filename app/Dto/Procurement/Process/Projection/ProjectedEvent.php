<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Projection;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\ProcessValue;

/**
 * Immutable факт из ledger `prc_process_transitions` для reducer. Stage и cohort work читаются из
 * неизменяемых полей work row (stage/cohort_key не меняются после создания), поэтому replay и
 * rebuild дают те же значения. Содержимое Redis/outbox payload источником не является.
 */
final readonly class ProjectedEvent
{
    public function __construct(
        public ProcessUuid $eventId,
        public string $entityKind,
        public int $entitySeq,
        public ?string $previousState,
        public string $newState,
        public ?string $reasonCode,
        public int $schemaVersion,
        public ?string $stage,
        public ?string $cohortKey,
    ) {
        ProcessValue::positive($entitySeq);
        ProcessValue::positive($schemaVersion);
        ProcessValue::text($entityKind);
        ProcessValue::text($newState);
    }

    /**
     * Измерения source work: stage и cohort читаются из неизменяемых полей work row; null, если
     * событие не work либо work недоступна.
     *
     * @return array{stage: string, cohort: string}|null
     */
    public function workSource(): ?array
    {
        return $this->stage === null || $this->cohortKey === null ? null : ['stage' => $this->stage, 'cohort' => $this->cohortKey];
    }
}
