<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\DeliveryGeneration;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use Illuminate\Support\Facades\DB;

/** Единственный writer prc_process_outbox; claim/publish consumers добавляют свои kind filters позже. */
final readonly class OutboxRepository
{
    public function __construct(private ProcessPayloadCodec $codec) {}

    /**
     * Wake одной delivery generation: dedup `wake:<work>:<dispatch_generation>`; конфликт — ошибка,
     * два wake одного admission невозможны. Задержка считается от DB clock.
     */
    public function enqueueWorkWake(ProcessUuid $messageId, WorkId $workId, DeliveryGeneration $dispatchGeneration, StageCode $stage, float $delaySeconds = 0.0): void
    {
        CallerTransaction::require('Work wake outbox insert');
        if ($delaySeconds < 0) {
            throw new \InvalidArgumentException('Wake delay cannot be negative.');
        }
        $payload = $this->codec->encodeWorkWake($workId, $dispatchGeneration, $messageId, $stage);
        DB::insert(
            "INSERT INTO prc_process_outbox (message_id, dedup_key, kind, payload, payload_schema_version, available_at)
             VALUES (?, ?, 'work_wake', ?::jsonb, ?, clock_timestamp() + make_interval(secs => ?))",
            [$messageId->value, 'wake:'.$workId->value->value.':'.$dispatchGeneration->value, $payload->json, $payload->schemaVersion, $delaySeconds],
        );
    }

    /**
     * Coalesced feeder wake lane (bp-sql-finalize): dedup `feed:<lane_key>:<epoch_second/5>` по DB
     * clock, ON CONFLICT DO NOTHING — 0 вставленных строк означает успешное coalescing, даже если
     * существующий wake уже опубликован; periodic feed tick остаётся safety net.
     */
    public function enqueueFeederWake(ProcessUuid $messageId, string $laneKey): void
    {
        CallerTransaction::require('Feeder wake outbox insert');
        $payload = $this->codec->encodeFeederWake($laneKey);
        DB::insert(
            "INSERT INTO prc_process_outbox (message_id, dedup_key, kind, payload, payload_schema_version, available_at)
             VALUES (?, 'feed:' || ? || ':' || floor(extract(epoch FROM clock_timestamp()) / 5)::bigint, 'feeder_wake', ?::jsonb, ?, clock_timestamp())
             ON CONFLICT (dedup_key) DO NOTHING",
            [$messageId->value, $laneKey, $payload->json, $payload->schemaVersion],
        );
    }

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
