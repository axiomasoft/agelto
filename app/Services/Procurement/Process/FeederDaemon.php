<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\ClaimedOutboxMessage;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DaemonTick;
use App\Dto\Procurement\Process\LanePolicy;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\DaemonKind;
use App\Enums\Procurement\Process\OutboxKind;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\OutboxRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\ProcessDaemon;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;

/**
 * Feeder daemon: periodic tick 2 s ±20% по всем parent lanes плюс
 * собственные feeder_wake, которые ускоряют tick. Claim-ит ТОЛЬКО kind feeder_wake и не трогает
 * work_wake/projection. Feeder wake — ускоритель, не authority: coalesced wake мог быть уже
 * опубликован, поэтому periodic tick остаётся страховкой. Сообщение помечается consumed только
 * после принятого feed tick; сбой tick освобождает claim и оставляет wake в outbox. Admission,
 * fairness и W/C принадлежат WorkFeeder под lane locks; здесь только расписание и outbox consumption.
 */
final class FeederDaemon implements ProcessDaemon
{
    private ?int $nextFullTickNs = null;

    /** @var array<string, ReconcileCategory> */
    private array $producers = [];

    public function registerProducer(ReconcileCategory $producer): void
    {
        $this->producers[$producer->name()] = $producer;
    }

    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly IncidentRepository $incidents,
        private readonly ProcessPayloadCodec $codec,
        private readonly WorkFeeder $feeder,
        private readonly ProcessPolicy $process,
        private readonly DaemonPolicy $policy,
        private readonly ProcessClock $clock,
        private readonly ProcessTransaction $transaction,
    ) {}

    public function kind(): DaemonKind
    {
        return DaemonKind::Feed;
    }

    public function tick(): DaemonTick
    {
        $token = ProcessUuid::random();
        /** @var list<ClaimedOutboxMessage> $wakes */
        $wakes = $this->transaction->run(fn (): array => $this->outbox->claim(OutboxKind::FeederWake, $token, $this->policy->feedWakeBatch, $this->policy->feedClaimSeconds));
        $now = $this->clock->monotonicNs();
        if ($wakes === [] && $this->nextFullTickNs !== null && $now < $this->nextFullTickNs) {
            return new DaemonTick(0, $this->outbox->quarantinedCount(OutboxKind::FeederWake) > 0 ? 'outbox_quarantined' : null);
        }

        try {
            $granted = 0;
            $firstFailure = null;
            foreach ($this->producers as $name => $producer) {
                try {
                    $granted += $producer->reconcile();
                } catch (\Throwable $failure) {
                    $firstFailure ??= $failure;
                    ProcessLog::daemonError(
                        daemon: 'feed',
                        code: 'feed_producer_failed',
                        failure: $failure,
                        context: ['producer' => $name],
                    );
                }
            }
            foreach ($this->parentLanes() as $lane) {
                try {
                    $granted += $this->feeder->tick($lane);
                } catch (\Throwable $failure) {
                    $firstFailure ??= $failure;
                    ProcessLog::daemonError(
                        daemon: 'feed',
                        code: 'feed_lane_failed',
                        failure: $failure,
                        context: ['lane' => $lane],
                    );
                }
            }
            if ($firstFailure !== null) {
                throw $firstFailure;
            }
        } catch (\Throwable $failure) {
            // Tick не принят: wakes остаются в outbox для следующего tick, исключение классифицирует loop.
            try {
                $this->outbox->releaseClaims($wakes, 'feed_tick_failed');
            } catch (\Throwable) {
            }

            throw $failure;
        }
        $this->nextFullTickNs = $now + DaemonPolicy::jittered($this->policy->feedTickSeconds, $this->policy->feedJitterPercent) * 1_000_000;

        $consumed = 0;
        foreach ($wakes as $wake) {
            if ($this->consume($wake)) {
                $consumed++;
            }
        }

        return new DaemonTick($granted + $consumed, $this->outbox->quarantinedCount(OutboxKind::FeederWake) > 0 ? 'outbox_quarantined' : null);
    }

    public function delayMs(DaemonTick $last): int
    {
        return $last->processed > 0 ? 0 : $this->policy->feedPollMs;
    }

    /** @return list<string> parent lane keys в стабильном порядке policy */
    private function parentLanes(): array
    {
        return array_values(array_map(
            static fn (LanePolicy $lane): string => $lane->key,
            array_filter($this->process->lanes(), static fn (LanePolicy $lane): bool => $lane->parentKey === null),
        ));
    }

    /** Valid wake принят feed tick; нечитаемый — quarantine с видимым кодом, а не молчаливая потеря. */
    private function consume(ClaimedOutboxMessage $wake): bool
    {
        if ($wake->payload->schemaVersion !== 1) {
            return $this->quarantine($wake, 'schema_unsupported');
        }
        try {
            $this->codec->decodeFeederWake($wake->payload);
        } catch (\InvalidArgumentException) {
            return $this->quarantine($wake, 'payload_invalid');
        }

        return $this->outbox->markPublished($wake);
    }

    private function quarantine(ClaimedOutboxMessage $wake, string $reason): false
    {
        $code = OutboxRepository::QUARANTINE_PREFIX.$reason;
        $ids = [ProcessUuid::random(), ProcessUuid::random(), ProcessUuid::random()];
        ProcessLog::quarantined($code, ['message_id' => $wake->messageId->value]);
        $this->transaction->run(function () use ($wake, $code, $ids): void {
            $this->outbox->quarantine($wake, $code, 3600);
            $this->incidents->open(
                fingerprint: ContentHash::of('outbox-quarantine:v1:feeder_wake:'.$code),
                scopeKey: 'outbox:feeder_wake',
                kind: 'outbox_quarantine',
                evidence: ['code' => $code, 'message_id' => $wake->messageId->value],
                incidentId: $ids[0],
                eventId: $ids[1],
                projectionMessageId: $ids[2],
            );
        });

        return false;
    }
}
