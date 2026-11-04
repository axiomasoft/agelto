<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\ClaimedOutboxMessage;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DaemonTick;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkWake;
use App\Enums\Procurement\Process\DaemonKind;
use App\Enums\Procurement\Process\OutboxKind;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\OutboxRepository;
use App\Services\Procurement\Process\Contracts\ProcessDaemon;
use App\Services\Procurement\Process\Contracts\WorkWakePublisher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Outbox relay: claim-ит ТОЛЬКО kind work_wake по token CAS в короткой tx, публикует
 * wake в Redis queue вне любой DB transaction и после успеха помечает сообщение published тем же
 * token. Падение до publish повторяет сообщение, падение после publish до mark даёт duplicate
 * delivery, безопасный по dispatch generation. Redis не authority: потеря опубликованного wake
 * восстанавливается scanners независимо от published_at. Сообщение, которое relay не умеет
 * исполнить (неподдерживаемая schema, нет маршрута), не помечается published и не теряется:
 * quarantine с видимым кодом, incident и persistent degraded readiness. Сбой publish освобождает
 * claim и оставляет сообщение в outbox; пауза и backoff — забота DaemonLoop.
 */
final class RelayDaemon implements ProcessDaemon
{
    /** Quarantined сообщение повторно рассматривается раз в час (после выкладки поддержки схемы). */
    private const int QUARANTINE_RETRY_SECONDS = 3600;

    /** Подряд идущие ticks с redis_publish_failed; сбрасывается ticks без ошибки. */
    private int $publishFailures = 0;

    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly IncidentRepository $incidents,
        private readonly ProcessPayloadCodec $codec,
        private readonly StageQueueMap $routes,
        private readonly WorkWakePublisher $publisher,
        private readonly ProcessTransaction $transaction,
        private readonly DaemonPolicy $policy,
    ) {}

    public function kind(): DaemonKind
    {
        return DaemonKind::Relay;
    }

    public function tick(): DaemonTick
    {
        $token = self::uuid();
        /** @var list<ClaimedOutboxMessage> $messages */
        $messages = $this->transaction->run(fn (): array => $this->outbox->claim(OutboxKind::WorkWake, $token, $this->policy->relayBatch, $this->policy->relayClaimSeconds));
        $published = 0;
        $error = null;
        foreach ($messages as $index => $message) {
            $wake = $this->decode($message);
            if (is_string($wake)) {
                $this->quarantine($message, $wake);

                continue;
            }
            try {
                $this->publisher->publish($wake);
            } catch (\Throwable $failure) {
                // Publish не состоялся: сообщение остаётся в outbox, остаток batch не держим claim-ом.
                ProcessLog::daemonError('relay', 'redis_publish_failed', $failure, ['message_id' => $message->messageId->value]);
                $this->release(array_slice($messages, $index), 'redis_publish_failed');
                $this->publishFailures++;

                return new DaemonTick($published, 'redis_publish_failed');
            }
            // Mark после publish: false означает takeover claim — duplicate допустим, mark чужого запрещён.
            if ($this->outbox->markPublished($message)) {
                $published++;
            }
        }
        if ($this->outbox->quarantinedCount(OutboxKind::WorkWake) > 0) {
            $error = 'outbox_quarantined';
        }
        if ($error === null) {
            $this->publishFailures = 0;
        }

        return new DaemonTick($published, $error);
    }

    public function delayMs(DaemonTick $last): int
    {
        // Недоступный Redis: backoff, даже если часть batch уже опубликована.
        if ($last->errorCode === 'redis_publish_failed') {
            return $this->policy->backoffMs($this->publishFailures);
        }

        return $last->processed > 0 ? 0 : random_int($this->policy->relayIdleMinMs, $this->policy->relayIdleMaxMs);
    }

    /** @return WorkWake|string wake либо quarantine-код сообщения, которое нельзя исполнить */
    private function decode(ClaimedOutboxMessage $message): WorkWake|string
    {
        if ($message->payload->schemaVersion !== 1) {
            return OutboxRepository::QUARANTINE_PREFIX.'schema_unsupported';
        }
        try {
            $wake = $this->codec->decodeWorkWake($message->payload);
        } catch (\InvalidArgumentException) {
            return OutboxRepository::QUARANTINE_PREFIX.'payload_invalid';
        }
        if (! $wake->envelope()->supported()) {
            return OutboxRepository::QUARANTINE_PREFIX.'schema_unsupported';
        }
        try {
            $this->routes->forStage($wake->stage);
        } catch (\LogicException) {
            return OutboxRepository::QUARANTINE_PREFIX.'route_missing';
        }

        return $wake;
    }

    private function quarantine(ClaimedOutboxMessage $message, string $code): void
    {
        $ids = [self::uuid(), self::uuid(), self::uuid()];
        ProcessLog::quarantined($code, ['message_id' => $message->messageId->value]);
        $this->transaction->run(function () use ($message, $code, $ids): void {
            $this->outbox->quarantine($message, $code, self::QUARANTINE_RETRY_SECONDS);
            $this->incidents->open(
                fingerprint: ContentHash::of('outbox-quarantine:v1:work_wake:'.$code),
                scopeKey: 'outbox:work_wake',
                kind: 'outbox_quarantine',
                evidence: ['code' => $code, 'message_id' => $message->messageId->value],
                incidentId: $ids[0],
                eventId: $ids[1],
                projectionMessageId: $ids[2],
            );
        });
    }

    /** @param list<ClaimedOutboxMessage> $messages */
    private function release(array $messages, string $code): void
    {
        try {
            foreach ($messages as $message) {
                $this->outbox->releaseClaim($message, $code);
            }
        } catch (QueryException $exception) {
            // Claim истечёт сам; transient DB здесь не должна маскировать исходную причину publish.
            if (! DatabaseFailureClassifier::isTransient($exception)) {
                throw $exception;
            }
        }
    }

    private static function uuid(): ProcessUuid
    {
        return new ProcessUuid(Str::lower((string) Str::uuid()));
    }
}
