<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\ProcessUuid;
use Illuminate\Support\Facades\DB;

/** Единственный writer prc_process_outbox; claim/publish consumers добавляют свои kind filters позже. */
final readonly class OutboxRepository
{
    /** Projection intent события: dedup `project:<event_id>`, конфликт — ошибка, не coalescing. */
    public function enqueueProjection(ProcessUuid $messageId, ProcessUuid $eventId): void
    {
        CallerTransaction::require('Projection outbox insert');
        DB::insert(
            "INSERT INTO prc_process_outbox (message_id, dedup_key, kind, payload, payload_schema_version, available_at)
             VALUES (?, ?, 'projection', ?::jsonb, 1, clock_timestamp())",
            [$messageId->value, 'project:'.$eventId->value, json_encode(['event_id' => $eventId->value], JSON_THROW_ON_ERROR)],
        );
    }
}
