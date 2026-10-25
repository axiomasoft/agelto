<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\ActorKind;
use Illuminate\Support\Facades\DB;

/**
 * Журнал transitions: каждое событие атомарно получает projection outbox (bp-ddl-core). Entity
 * sequence authority — transition_seq самой work/run row; event/message ID стабильны при retry.
 */
final readonly class TransitionRepository
{
    public function __construct(private OutboxRepository $outbox) {}

    public function recordWork(
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
        ProcessUuid $workId,
        int $entitySeq,
        ?string $previousState,
        string $newState,
        ?string $reasonCode,
        ActorKind $actor,
        ?int $generation,
    ): void {
        $this->record($eventId, $projectionMessageId, 'work', $workId, $entitySeq, $previousState, $newState, $reasonCode, $actor, $generation);
    }

    public function recordRun(
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
        ProcessUuid $runId,
        int $entitySeq,
        ?string $previousState,
        string $newState,
        ?string $reasonCode,
        ActorKind $actor,
    ): void {
        $this->record($eventId, $projectionMessageId, 'run', $runId, $entitySeq, $previousState, $newState, $reasonCode, $actor, null);
    }

    private function record(
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
        string $entityKind,
        ProcessUuid $entityId,
        int $entitySeq,
        ?string $previousState,
        string $newState,
        ?string $reasonCode,
        ActorKind $actor,
        ?int $generation,
    ): void {
        CallerTransaction::require('Transition insert');
        $entityColumn = $entityKind.'_id';
        DB::insert(
            "INSERT INTO prc_process_transitions
               (event_id, entity_kind, {$entityColumn}, entity_seq, previous_state, new_state, reason_code, actor_kind, generation, schema_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
            [$eventId->value, $entityKind, $entityId->value, $entitySeq, $previousState, $newState, $reasonCode, $actor->value, $generation],
        );
        $this->outbox->enqueueProjection($projectionMessageId, $eventId);
    }
}
