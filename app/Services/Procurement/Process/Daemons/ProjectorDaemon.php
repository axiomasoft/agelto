<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Daemons;

use App\Dto\Procurement\Process\Execution\ClaimedOutboxMessage;
use App\Dto\Procurement\Process\Governance\DaemonTick;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Core\OutboxKind;
use App\Enums\Procurement\Process\Governance\DaemonKind;
use App\Enums\Procurement\Process\Projection\ProjectionApplyStatus;
use App\Repositories\Procurement\Process\Authority\IncidentRepository;
use App\Repositories\Procurement\Process\Work\OutboxRepository;
use App\Services\Procurement\Process\Contracts\ProcessDaemon;
use App\Services\Procurement\Process\Infrastructure\DatabaseFailureClassifier;
use App\Services\Procurement\Process\Infrastructure\ProcessLog;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use App\Services\Procurement\Process\Projection\MetricProjector;
use App\Services\Procurement\Process\Projection\ProjectionPolicy;
use Closure;

/**
 * Projector daemon: tick 1 s, claim-ит ТОЛЬКО kind projection по
 * token CAS и применяет событие всем registered reducers. Сообщение подтверждается (published) лишь
 * после receipts всех обязательных получателей: частичный crash/replay безопасен, потому что дубликат
 * отсекает receipt. Maintenance откладывает сообщение без degraded; неподдерживаемая schema, неверный
 * payload, расхождение catalog_hash или событие вне каталога уходят в quarantine с incident и
 * persistent degraded readiness, но не блокируют остальные event IDs. Source, удалённый retention,
 * подтверждается с нулевым приращением: факт из queue payload не воссоздаётся.
 */
final class ProjectorDaemon implements ProcessDaemon
{
    private const int MAX_APPLY_ATTEMPTS = 5;

    private const string APPLY_FAILED = 'projection_apply_failed';

    private bool $statesEnsured = false;

    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly IncidentRepository $incidents,
        private readonly ProcessPayloadCodec $codec,
        private readonly MetricProjector $projector,
        private readonly ProjectionPolicy $projection,
        private readonly DaemonPolicy $policy,
        private readonly ProcessTransaction $transaction,
    ) {}

    public function kind(): DaemonKind
    {
        return DaemonKind::Project;
    }

    public function tick(?Closure $heartbeat = null): DaemonTick
    {
        if (! $this->statesEnsured) {
            $this->projector->ensureStates();
            $this->statesEnsured = true;
        }
        $token = ProcessUuid::random();
        /** @var list<ClaimedOutboxMessage> $messages */
        $messages = $this->transaction->run(fn (): array => $this->outbox->claim(OutboxKind::Projection, $token, $this->policy->projectBatch, $this->policy->projectClaimSeconds));
        $acknowledged = 0;
        foreach ($messages as $index => $message) {
            $heartbeat?->__invoke();
            try {
                if ($this->handle($message)) {
                    $acknowledged++;
                }
            } catch (\Throwable $failure) {
                // Необработанные claims возвращаются в outbox сразу; исключение классифицирует DaemonLoop.
                $this->release(array_slice($messages, $index));

                throw $failure;
            }
        }

        return new DaemonTick($acknowledged, $this->outbox->quarantinedCount(OutboxKind::Projection) > 0 ? 'outbox_quarantined' : null);
    }

    public function delayMs(DaemonTick $last): int
    {
        return $last->processed > 0 ? 0 : $this->policy->projectTickSeconds * 1000;
    }

    /** true — сообщение подтверждено (все получатели имеют receipt либо source недоступен). */
    private function handle(ClaimedOutboxMessage $message): bool
    {
        try {
            $eventId = $this->codec->decodeProjection($message->payload);
        } catch (\InvalidArgumentException) {
            $this->quarantine($message, $message->payload->schemaVersion === 1 ? 'payload_invalid' : 'schema_unsupported', null);

            return false;
        }
        try {
            $results = $this->projector->apply($eventId);
        } catch (\Throwable $failure) {
            if (DatabaseFailureClassifier::isTransient($failure)) {
                throw $failure;
            }
            ProcessLog::daemonError(
                daemon: 'project',
                code: self::APPLY_FAILED,
                failure: $failure,
                context: ['message_id' => $message->messageId->value, 'attempts' => $message->attempts],
            );
            if ($message->attempts >= self::MAX_APPLY_ATTEMPTS) {
                $this->quarantine(message: $message, reason: 'apply_failed', eventId: $eventId);
            } else {
                $this->outbox->defer(
                    message: $message,
                    code: self::APPLY_FAILED,
                    delaySeconds: intdiv($this->policy->backoffMs($message->attempts), 1000),
                );
            }

            return false;
        }
        $quarantine = null;
        $deferred = false;
        foreach ($results as $status) {
            if ($status->quarantined()) {
                $quarantine ??= $status;
            } elseif (! $status->settled()) {
                $deferred = true;
            }
        }
        if ($quarantine !== null) {
            $this->quarantine($message, $quarantine->value, $eventId);

            return false;
        }
        if ($deferred) {
            $this->outbox->defer($message, 'projection_maintenance', $this->projection->maintenanceDeferSeconds);

            return false;
        }
        if (in_array(ProjectionApplyStatus::SourceUnavailable, $results, true)) {
            ProcessLog::quarantined('projection_source_unavailable', ['event_id' => $eventId->value]);
        }

        return $this->outbox->markPublished($message);
    }

    private function quarantine(ClaimedOutboxMessage $message, string $reason, ?ProcessUuid $eventId): void
    {
        $code = OutboxRepository::QUARANTINE_PREFIX.'projection_'.$reason;
        $ids = [ProcessUuid::random(), ProcessUuid::random(), ProcessUuid::random()];
        ProcessLog::quarantined($code, ['message_id' => $message->messageId->value]);
        $this->transaction->run(function () use ($message, $code, $reason, $eventId, $ids): void {
            if (! $this->outbox->quarantine($message, $code, $this->projection->quarantineRetrySeconds)) {
                return;
            }
            $this->incidents->open(
                fingerprint: ContentHash::of('outbox-quarantine:v1:projection:'.$reason),
                scopeKey: 'outbox:projection',
                kind: $reason === 'apply_failed' ? 'outbox_quarantine' : 'projection_schema_error',
                evidence: ['code' => $code, 'message_id' => $message->messageId->value, ...($eventId === null ? [] : ['event_id' => $eventId->value])],
                incidentId: $ids[0],
                eventId: $ids[1],
                projectionMessageId: $ids[2],
            );
        });
    }

    /** @param list<ClaimedOutboxMessage> $messages */
    private function release(array $messages): void
    {
        try {
            $this->outbox->releaseClaims(messages: $messages, errorCode: self::APPLY_FAILED);
        } catch (\Throwable $failure) {
            // Claim истечёт сам; вторичный сбой не должен скрывать исходную причину tick.
            ProcessLog::daemonError('project', 'release_claim_failed', $failure);
        }
    }
}
