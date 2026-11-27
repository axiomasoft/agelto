<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process\Projection;

use App\Dto\Procurement\Process\Governance\TransitionData;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Work\OutboxRepository;
use Illuminate\Support\Facades\DB;

/**
 * Журнал transitions: каждое событие атомарно получает projection outbox. Entity
 * sequence authority — transition_seq самой work/run row; event/message ID стабильны при retry.
 */
final readonly class TransitionRepository
{
    public function __construct(private OutboxRepository $outbox) {}

    public function recordWork(TransitionData $transition): void
    {
        $this->record(entityKind: 'work', transition: $transition);
    }

    public function recordRun(TransitionData $transition): void
    {
        $this->record(entityKind: 'run', transition: $transition);
    }

    public function recordControl(TransitionData $transition): void
    {
        $this->record(entityKind: 'control', transition: $transition);
    }

    public function recordIncident(TransitionData $transition): void
    {
        $this->record(entityKind: 'incident', transition: $transition);
    }

    private function record(string $entityKind, TransitionData $transition): void
    {
        CallerTransaction::require('Transition insert');
        $entityColumn = $entityKind.'_id';
        $hasDetails = $transition->details !== [];
        DB::insert(
            "INSERT INTO prc_process_transitions
               (event_id, entity_kind, {$entityColumn}, entity_seq, previous_state, new_state, reason_code, actor_kind, actor_id, generation,
                details, details_schema_version, schema_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, 1)",
            [
                $transition->eventId->value, $entityKind, $transition->entityId->value, $transition->entitySeq,
                $transition->previousState, $transition->newState, $transition->reasonCode, $transition->actor->value,
                $transition->actorId, $entityKind === 'work' ? $transition->generation : null,
                $hasDetails ? json_encode($transition->details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $hasDetails ? 1 : null,
            ],
        );
        $this->outbox->enqueueProjection($transition->projectionMessageId, $transition->eventId);
    }
}
