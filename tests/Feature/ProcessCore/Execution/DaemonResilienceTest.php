<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\Duration;
use App\Enums\Procurement\Process\DaemonHealth;
use App\Enums\Procurement\Process\DaemonKind;
use App\Enums\Procurement\Process\OutboxKind;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\DaemonHeartbeatRepository;
use App\Repositories\Procurement\Process\OutboxRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Process\DaemonLoop;
use App\Services\Procurement\Process\DaemonPolicy;
use App\Services\Procurement\Process\FeederDaemon;
use App\Services\Procurement\Process\ProjectorDaemon;
use App\Services\Procurement\Process\ReconcileDaemon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProcessCore\Observability\ProjectionFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('delivery-and-daemon-lifecycle');

test('проектор откладывает сбойное событие с растущей задержкой, обрабатывает соседа и на пятой попытке помещает событие в карантин', function (): void {
    $daemon = app(ProjectorDaemon::class);
    ['work' => $work] = ProjectionFixtures::workInRun();
    $daemon->tick();
    $broken = ProjectionFixtures::transition(work: $work, new: 'running', previous: 'admitted');
    $healthy = ProjectionFixtures::transition(work: $work, new: 'succeeded', previous: 'running');
    $message = DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$broken->value);
    DB::beforeExecuting(static function (string $query, array $bindings) use ($broken): void {
        if (str_starts_with($query, 'SELECT entity_kind FROM prc_process_transitions') && $bindings === [$broken->value]) {
            throw new RuntimeException('fixture projection failure');
        }
    });

    foreach ([1, 2, 4, 8] as $index => $delay) {
        $message->update(['available_at' => DB::raw("clock_timestamp() - interval '1 minute'")]);
        $before = DB::scalar('SELECT clock_timestamp()');
        $tick = $daemon->tick();
        $row = (clone $message)->sole();
        expect($tick->processed)->toBe($index === 0 ? 1 : 0)
            ->and($tick->errorCode)->toBeNull()
            ->and($row->attempts)->toBe($index + 1)
            ->and($row->last_error_code)->toBe('projection_apply_failed')
            ->and([$row->published_at, $row->claim_token, $row->claim_until])->toBe([null, null, null])
            ->and((clone $message)->whereRaw('available_at BETWEEN ?::timestamptz + make_interval(secs => ?) AND clock_timestamp() + make_interval(secs => ?)', [$before, $delay, $delay])->exists())->toBeTrue();
    }

    $message->update(['available_at' => DB::raw('clock_timestamp()')]);
    expect($daemon->tick()->errorCode)->toBe('outbox_quarantined');
    $row = (clone $message)->sole();
    expect($row->attempts)->toBe(5)
        ->and($row->last_error_code)->toBe('quarantined_projection_apply_failed')
        ->and([$row->published_at, $row->claim_token, $row->claim_until])->toBe([null, null, null])
        ->and((clone $message)->whereRaw('available_at > clock_timestamp()')->exists())->toBeTrue()
        ->and(DB::table('prc_process_projection_receipts')->where('event_id', $healthy->value)->exists())->toBeTrue()
        ->and(DB::table('prc_process_incidents')->where('scope_key', 'outbox:projection')->where('evidence->event_id', $broken->value)->count())->toBe(1);
});

test('временный сбой БД прерывает тик проектора и освобождает всю оставшуюся пачку', function (): void {
    $outbox = app(OutboxRepository::class);
    $broken = LedgerFixtures::uuid();
    $first = LedgerFixtures::uuid();
    $second = LedgerFixtures::uuid();
    $outbox->enqueueProjection(messageId: $first, eventId: $broken);
    $outbox->enqueueProjection(messageId: $second, eventId: LedgerFixtures::uuid());
    $message = DB::table('prc_process_outbox')->where('message_id', $first->value);
    $message->update(['attempts' => 4, 'available_at' => DB::raw("clock_timestamp() - interval '1 minute'")]);
    $available = $message->value('available_at');
    $failure = new PDOException('fixture DB outage');
    $failure->errorInfo = ['08006'];
    DB::beforeExecuting(static function (string $query, array $bindings) use ($broken, $failure): void {
        if (str_starts_with($query, 'SELECT entity_kind FROM prc_process_transitions') && $bindings === [$broken->value]) {
            throw $failure;
        }
    });

    expect(fn () => app(ProjectorDaemon::class)->tick())->toThrow(PDOException::class, 'fixture DB outage');

    $batch = DB::table('prc_process_outbox')->whereIn('message_id', [$first->value, $second->value]);
    expect($message->value('attempts'))->toBe(5)
        ->and($message->value('available_at'))->toBe($available)
        ->and($batch->whereNull('published_at')->whereNull('claim_token')->whereNull('claim_until')->where('last_error_code', 'projection_apply_failed')->count())->toBe(2)
        ->and($outbox->quarantinedCount(OutboxKind::Projection))->toBe(0);
});

test('во время долгого тика сверки heartbeat обновляется между категориями и соблюдает интервал', function (): void {
    $policy = app(DaemonPolicy::class);
    $heartbeats = app(DaemonHeartbeatRepository::class);
    $instance = LedgerFixtures::uuid();
    $clock = new class implements ProcessClock
    {
        private int $now = 0;

        public function dbNow(): DbInstant
        {
            return new DbInstant(new DateTimeImmutable('2026-10-26T00:00:00Z'));
        }

        public function monotonicNs(): int
        {
            return $this->now;
        }

        public function sleep(Duration $duration): void
        {
            $this->now += $duration->nanos();
        }
    };
    $daemon = new ReconcileDaemon($policy);
    $updates = 0;
    $observed = [];
    $started = false;
    DB::listen(static function (QueryExecuted $query) use (&$updates): void {
        if (str_starts_with($query->sql, 'UPDATE prc_process_daemon_heartbeats SET last_tick_at')) {
            $updates++;
        }
    });
    $slow = static function () use ($clock, $policy, $instance, $heartbeats, &$updates, &$observed, &$started): void {
        $started = true;
        DB::table('prc_process_daemon_heartbeats')->where('instance_id', $instance->value)->update([
            'last_tick_at' => DB::raw("clock_timestamp() - interval '1 hour'"),
        ]);
        $clock->sleep(Duration::seconds($policy->liveSeconds + 1));
        $observed[] = [$heartbeats->health(DaemonKind::Reconcile, $policy->liveSeconds), $updates];
    };
    $inspect = static function () use ($heartbeats, $policy, &$updates, &$observed): void {
        $observed[] = [$heartbeats->health(DaemonKind::Reconcile, $policy->liveSeconds), $updates];
    };
    foreach (['slow' => $slow, 'inspect' => $inspect, 'inspect_again' => $inspect] as $name => $category) {
        $daemon->register(new class($name, $category) implements ReconcileCategory
        {
            public function __construct(private readonly string $name, private readonly Closure $category) {}

            public function name(): string
            {
                return $this->name;
            }

            public function reconcile(): int
            {
                ($this->category)();

                return 1;
            }
        });
    }

    $loop = new DaemonLoop(clock: $clock, heartbeats: $heartbeats, policy: $policy, memoryUsage: static fn (): int => 0);
    expect($loop->run(daemon: $daemon, instance: $instance, shouldStop: static function () use (&$started): bool {
        return $started;
    }))->toBe(DaemonLoop::STOP_SIGNAL)
        ->and($observed)->toBe([[DaemonHealth::Down, 0], [DaemonHealth::Healthy, 1], [DaemonHealth::Healthy, 1]])
        ->and($updates)->toBe(1);
});

test('при сбое producer и одной lane feeder одним обновлением подтверждает wakes здоровой lane', function (): void {
    ExecutionFixtures::authority([StageCode::Listing, StageCode::Prepare]);
    $work = ExecutionFixtures::listingWork();
    $outbox = app(OutboxRepository::class);
    $parent = LedgerFixtures::uuid();
    $child = LedgerFixtures::uuid();
    $failed = LedgerFixtures::uuid();
    $outbox->enqueueFeederWake(messageId: $failed, laneKey: 'prepare');
    $outbox->enqueueFeederWake(messageId: $parent, laneKey: 'acquisition');
    $outbox->enqueueFeederWake(messageId: $child, laneKey: 'acquisition.listing');
    $daemon = app(FeederDaemon::class);
    $daemon->registerProducer(new class implements ReconcileCategory
    {
        public function name(): string
        {
            return 'fixture_failed_producer';
        }

        public function reconcile(): int
        {
            throw new RuntimeException('fixture producer failure');
        }
    });
    DB::beforeExecuting(static function (string $query, array $bindings): void {
        if (str_contains($query, 'FROM prc_process_lanes l WHERE') && in_array('prepare', $bindings, true)) {
            throw new RuntimeException('fixture lane failure');
        }
    });
    $publishUpdates = [];
    DB::listen(static function (QueryExecuted $query) use (&$publishUpdates): void {
        if (str_starts_with($query->sql, 'UPDATE prc_process_outbox SET published_at')) {
            $publishUpdates[] = $query->sql;
        }
    });

    expect(fn () => $daemon->tick())->toThrow(RuntimeException::class, 'fixture producer failure');

    $waiting = DB::table('prc_process_outbox')->where('message_id', $failed->value)->sole();
    expect(ExecutionFixtures::work($work)->state)->toBe('admitted')
        ->and(DB::table('prc_process_outbox')->whereIn('message_id', [$parent->value, $child->value])->whereNotNull('published_at')->whereNull('claim_token')->whereNull('last_error_code')->count())->toBe(2)
        ->and([$waiting->published_at, $waiting->claim_token, $waiting->claim_until, $waiting->last_error_code])->toBe([null, null, null, 'feed_tick_failed'])
        ->and($publishUpdates)->toHaveCount(1)
        ->and($publishUpdates[0])->toContain('message_id = ANY(?::uuid[])');
});

test('массовое освобождение выполняет один запрос и сохраняет чужой захват и опубликованную запись', function (): void {
    $outbox = app(OutboxRepository::class);
    $feeder = LedgerFixtures::uuid();
    $projection = LedgerFixtures::uuid();
    $stale = LedgerFixtures::uuid();
    $published = LedgerFixtures::uuid();
    $outbox->enqueueFeederWake(messageId: $feeder, laneKey: 'acquisition');
    foreach ([$projection, $stale, $published] as $message) {
        $outbox->enqueueProjection(messageId: $message, eventId: LedgerFixtures::uuid());
    }
    $claims = [
        ...$outbox->claim(kind: OutboxKind::FeederWake, token: LedgerFixtures::uuid(), limit: 10, claimSeconds: 30),
        ...$outbox->claim(kind: OutboxKind::Projection, token: LedgerFixtures::uuid(), limit: 10, claimSeconds: 30),
    ];
    foreach ($claims as $claim) {
        if ($claim->messageId->value === $published->value) {
            expect($outbox->markPublished($claim))->toBeTrue();
        }
    }
    $owner = LedgerFixtures::uuid();
    DB::table('prc_process_outbox')->where('message_id', $stale->value)->update(['claim_token' => $owner->value]);
    $releaseUpdates = [];
    DB::listen(static function (QueryExecuted $query) use (&$releaseUpdates): void {
        if (str_starts_with($query->sql, 'UPDATE prc_process_outbox SET claim_token')) {
            $releaseUpdates[] = $query->sql;
        }
    });

    $outbox->releaseClaims(messages: [], errorCode: 'fixture_release');
    $outbox->releaseClaims(messages: $claims, errorCode: 'fixture_release');

    $rows = DB::table('prc_process_outbox')->whereIn('message_id', [$feeder->value, $projection->value, $stale->value, $published->value])->get()->keyBy('message_id');
    foreach ([$feeder, $projection] as $id) {
        $row = $rows[$id->value];
        expect([$row->claim_token, $row->claim_until, $row->last_error_code])->toBe([null, null, 'fixture_release']);
    }
    expect($rows[$stale->value]->claim_token)->toBe($owner->value)
        ->and($rows[$stale->value]->last_error_code)->toBeNull()
        ->and($rows[$published->value]->published_at)->not->toBeNull()
        ->and($rows[$published->value]->last_error_code)->toBeNull()
        ->and($releaseUpdates)->toHaveCount(1)
        ->and($releaseUpdates[0])->toContain('message_id = ANY(?::uuid[])');
});
