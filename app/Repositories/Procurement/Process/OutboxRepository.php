<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\ClaimedOutboxMessage;
use App\Dto\Procurement\Process\DeliveryGeneration;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\OutboxKind;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use Illuminate\Support\Facades\DB;

/**
 * Единственный writer prc_process_outbox. Producers (enqueue*) требуют транзакцию вызывающего;
 * consumers (relay, feeder, projector) claim-ят только свой kind по token CAS в короткой tx и
 * выполняют side effects вне неё.
 */
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
     * Coalesced feeder wake lane: dedup `feed:<lane_key>:<epoch_second/5>` по DB
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

    /**
     * Token claim только СВОЕГО kind: один statement, `FOR UPDATE SKIP LOCKED` на
     * bounded batch, expired claim забирается заново. Side effects consumer выполняет после commit
     * claim; чужой kind не затрагивается никогда. Сообщения возвращаются в порядке available_at.
     *
     * @return list<ClaimedOutboxMessage>
     */
    public function claim(OutboxKind $kind, ProcessUuid $token, int $limit, int $claimSeconds): array
    {
        if ($limit < 1 || $claimSeconds < 1) {
            throw new \InvalidArgumentException('Outbox claim needs a positive batch and claim duration.');
        }
        // Кандидаты материализуются один раз: `UPDATE … WHERE id IN (SELECT … LIMIT n)` пересканирует
        // подзапрос и под конкуренцией может обновить больше n строк (Halloween/self-modified skip).
        $rows = LedgerRow::all(
            'WITH picked AS MATERIALIZED (
                 SELECT message_id FROM prc_process_outbox
                 WHERE kind = ? AND published_at IS NULL AND available_at <= clock_timestamp()
                   AND (claim_until IS NULL OR claim_until <= clock_timestamp())
                 ORDER BY available_at, message_id LIMIT ? FOR UPDATE SKIP LOCKED
             )
             UPDATE prc_process_outbox o
             SET claim_token = ?, claim_until = clock_timestamp() + make_interval(secs => ?), attempts = attempts + 1
             FROM picked
             WHERE o.message_id = picked.message_id
             RETURNING o.message_id, o.kind, o.payload::text AS payload, o.payload_schema_version, o.attempts, o.available_at::text AS available_at',
            [$kind->value, $limit, $token->value, $claimSeconds],
        );
        usort($rows, static fn (LedgerRow $a, LedgerRow $b): int => [$a->string('available_at'), $a->string('message_id')] <=> [$b->string('available_at'), $b->string('message_id')]);

        return array_map(static fn (LedgerRow $row): ClaimedOutboxMessage => new ClaimedOutboxMessage(
            messageId: new ProcessUuid($row->string('message_id')),
            kind: OutboxKind::from($row->string('kind')),
            payload: new VersionedPayload($row->string('payload'), $row->int('payload_schema_version')),
            claimToken: $token,
            attempts: $row->int('attempts'),
        ), $rows);
    }

    /**
     * CAS mark после успешного publish: только держатель текущего claim token. Consumer, чей claim
     * перехвачен, ничего не помечает; false — сообщение будет доставлено повторно (duplicate допустим).
     */
    public function markPublished(ClaimedOutboxMessage $message): bool
    {
        return DB::update(
            'UPDATE prc_process_outbox SET published_at = clock_timestamp(), claim_token = NULL, claim_until = NULL, last_error_code = NULL
             WHERE message_id = ? AND kind = ? AND published_at IS NULL AND claim_token = ?',
            [$message->messageId->value, $message->kind->value, $message->claimToken->value],
        ) === 1;
    }

    /** Publish не удался: claim снимается, код ошибки сохраняется, повтор — следующим tick consumer. */
    public function releaseClaim(ClaimedOutboxMessage $message, string $errorCode): bool
    {
        return DB::update(
            'UPDATE prc_process_outbox SET claim_token = NULL, claim_until = NULL, last_error_code = ?
             WHERE message_id = ? AND kind = ? AND published_at IS NULL AND claim_token = ?',
            [$errorCode, $message->messageId->value, $message->kind->value, $message->claimToken->value],
        ) === 1;
    }

    /** @param list<ClaimedOutboxMessage> $messages */
    public function releaseClaims(array $messages, string $errorCode): void
    {
        foreach ($messages as $message) {
            $this->releaseClaim($message, $errorCode);
        }
    }

    /**
     * Сообщение, которое этот consumer не умеет исполнить (неподдерживаемая schema, нет маршрута):
     * остаётся unpublished с видимым кодом и откладывается, а не помечается published и не теряется.
     */
    public function quarantine(ClaimedOutboxMessage $message, string $errorCode, int $retrySeconds): bool
    {
        return DB::update(
            'UPDATE prc_process_outbox
             SET claim_token = NULL, claim_until = NULL, last_error_code = ?, available_at = clock_timestamp() + make_interval(secs => ?)
             WHERE message_id = ? AND kind = ? AND published_at IS NULL AND claim_token = ?',
            [$errorCode, $retrySeconds, $message->messageId->value, $message->kind->value, $message->claimToken->value],
        ) === 1;
    }

    /**
     * Временная отсрочка без quarantine (например maintenance projector): claim снимается, сообщение
     * снова становится доступным через $delaySeconds по DB clock; readiness degraded не вызывает.
     */
    public function defer(ClaimedOutboxMessage $message, string $code, int $delaySeconds): bool
    {
        return DB::update(
            'UPDATE prc_process_outbox
             SET claim_token = NULL, claim_until = NULL, last_error_code = ?, available_at = clock_timestamp() + make_interval(secs => ?)
             WHERE message_id = ? AND kind = ? AND published_at IS NULL AND claim_token = ?',
            [$code, $delaySeconds, $message->messageId->value, $message->kind->value, $message->claimToken->value],
        ) === 1;
    }

    /** Код ошибки quarantine всегда начинается с этого префикса: retry publish его не использует. */
    public const string QUARANTINE_PREFIX = 'quarantined_';

    /** Число сообщений kind, удерживаемых quarantine: persistent признак degraded readiness. */
    public function quarantinedCount(OutboxKind $kind): int
    {
        return (LedgerRow::one(
            'SELECT count(*) AS held FROM prc_process_outbox WHERE kind = ? AND published_at IS NULL AND left(last_error_code, ?) = ?',
            [$kind->value, strlen(self::QUARANTINE_PREFIX), self::QUARANTINE_PREFIX],
        ) ?? throw new \LogicException('Aggregate query always returns a row.'))->int('held');
    }

    /** Возраст самого раннего готового unpublished сообщения kind (lag для readiness), null — очередь пуста. */
    public function lagSeconds(OutboxKind $kind): ?int
    {
        return LedgerRow::one(
            'SELECT floor(extract(epoch FROM clock_timestamp() - min(available_at)))::bigint AS lag
             FROM prc_process_outbox WHERE kind = ? AND published_at IS NULL AND available_at <= clock_timestamp()',
            [$kind->value],
        )?->nullableInt('lag');
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
