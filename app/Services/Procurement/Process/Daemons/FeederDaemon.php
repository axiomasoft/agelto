<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Daemons;

use App\Dto\Procurement\Process\Execution\ClaimedOutboxMessage;
use App\Dto\Procurement\Process\Governance\DaemonTick;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Core\OutboxKind;
use App\Enums\Procurement\Process\Governance\DaemonKind;
use App\Repositories\Procurement\Process\Authority\IncidentRepository;
use App\Repositories\Procurement\Process\Work\OutboxRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\ProcessDaemon;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Infrastructure\ProcessLog;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;
use App\Services\Procurement\Process\Infrastructure\ProcessPolicy;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Closure;

/**
 * Feeder daemon: periodic tick 2 s ±20% по всем parent lanes плюс
 * собственные feeder_wake, которые ускоряют tick. Claim-ит ТОЛЬКО kind feeder_wake и не трогает
 * work_wake/projection. Feeder wake — ускоритель, не authority: coalesced wake мог быть уже
 * опубликован, поэтому periodic tick остаётся страховкой. Wakes успешных lanes подтверждаются
 * даже при частичном сбое; claim неуспешной lane освобождается для следующего tick. Admission,
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

    public function tick(?Closure $heartbeat = null): DaemonTick
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
            $parentByLane = array_column($this->process->lanes(), 'parentKey', 'key');
            $failedLanes = [];
            foreach ($parentByLane as $lane => $parent) {
                if ($parent !== null) {
                    continue;
                }
                try {
                    $granted += $this->feeder->tick($lane);
                } catch (\Throwable $failure) {
                    $failedLanes[$lane] = true;
                    $firstFailure ??= $failure;
                    ProcessLog::daemonError(
                        daemon: 'feed',
                        code: 'feed_lane_failed',
                        failure: $failure,
                        context: ['lane' => $lane],
                    );
                }
            }
            $consumed = $this->consume(wakes: $wakes, parentByLane: $parentByLane, failedLanes: $failedLanes);
            if ($firstFailure !== null) {
                throw $firstFailure;
            }
        } catch (\Throwable $failure) {
            // CAS освобождает только оставшиеся claims. Исходный сбой классифицирует loop.
            try {
                $this->outbox->releaseClaims(messages: $wakes, errorCode: 'feed_tick_failed');
            } catch (\Throwable $releaseFailure) {
                ProcessLog::daemonError(daemon: 'feed', code: 'release_claim_failed', failure: $releaseFailure);
            }

            throw $failure;
        }
        $this->nextFullTickNs = $now + DaemonPolicy::jittered($this->policy->feedTickSeconds, $this->policy->feedJitterPercent) * 1_000_000;

        return new DaemonTick($granted + $consumed, $this->outbox->quarantinedCount(OutboxKind::FeederWake) > 0 ? 'outbox_quarantined' : null);
    }

    public function delayMs(DaemonTick $last): int
    {
        return $last->processed > 0 ? 0 : $this->policy->feedPollMs;
    }

    /**
     * @param  list<ClaimedOutboxMessage>  $wakes
     * @param  array<string, ?string>  $parentByLane
     * @param  array<string, true>  $failedLanes
     */
    private function consume(array $wakes, array $parentByLane, array $failedLanes): int
    {
        $accepted = [];
        foreach ($wakes as $wake) {
            if ($wake->payload->schemaVersion !== 1) {
                $this->quarantine(wake: $wake, reason: 'schema_unsupported');

                continue;
            }
            try {
                $lane = $this->codec->decodeFeederWake($wake->payload);
            } catch (\InvalidArgumentException) {
                $this->quarantine(wake: $wake, reason: 'payload_invalid');

                continue;
            }
            if (! isset($failedLanes[$parentByLane[$lane] ?? $lane])) {
                $accepted[] = $wake;
            }
        }

        return $this->outbox->markPublishedBatch($accepted);
    }

    private function quarantine(ClaimedOutboxMessage $wake, string $reason): void
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
    }
}
