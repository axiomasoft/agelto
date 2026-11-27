<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Execution\WorkWake;
use App\Dto\Procurement\Process\Governance\DaemonTick;
use App\Dto\Procurement\Process\Inputs\PrepareInput;
use App\Dto\Procurement\Process\Primitives\DbInstant;
use App\Dto\Procurement\Process\Primitives\Duration;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\DaemonHealth;
use App\Enums\Procurement\Process\Governance\DaemonKind;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Process\Authority\DaemonHeartbeatRepository;
use App\Services\Procurement\Document\PreparePolicy;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\ProcessDaemon;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Process\Contracts\WorkWakePublisher;
use App\Services\Procurement\Process\Daemons\DaemonLoop;
use App\Services\Procurement\Process\Daemons\DaemonPolicy;
use App\Services\Procurement\Process\Daemons\DaemonReadiness;
use App\Services\Procurement\Process\Daemons\FeederDaemon;
use App\Services\Procurement\Process\Daemons\ReconcileDaemon;
use App\Services\Procurement\Process\Daemons\RelayDaemon;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('delivery-and-daemon-lifecycle');

/** Fake monotonic/DB clock: sleep продвигает время, ожидание не реальное. */
function loopClock(): object
{
    return new class implements ProcessClock
    {
        public int $now = 1_000_000_000;

        /** @var list<int> */
        public array $sleeps = [];

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
            $this->sleeps[] = $duration->milliseconds;
            $this->now += $duration->nanos();
        }
    };
}

function loopFor(ProcessClock $clock, ?Closure $memory = null): DaemonLoop
{
    // Память test-процесса не участвует в проверках loop, кроме явно заданного memory limit.
    return new DaemonLoop($clock, app(DaemonHeartbeatRepository::class), new DaemonPolicy(app('config')), $memory ?? static fn (): int => 0);
}

/** Daemon, чей tick задаёт тест; $delayMs — пауза после tick. */
function loopProbe(DaemonKind $kind, Closure $tick, int $delayMs = 0): object
{
    return new class($kind, $tick, $delayMs) implements ProcessDaemon
    {
        public int $ticks = 0;

        public function __construct(private readonly DaemonKind $kind, private readonly Closure $tick, private readonly int $delayMs) {}

        public function kind(): DaemonKind
        {
            return $this->kind;
        }

        public function tick(?Closure $heartbeat = null): DaemonTick
        {
            $this->ticks++;

            return ($this->tick)($this);
        }

        public function delayMs(DaemonTick $last): int
        {
            return $this->delayMs;
        }
    };
}

test('карантин feeder wake сохраняет degraded на idle polls до разрешения сообщения', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    app()->instance(ProcessClock::class, loopClock());
    $message = LedgerFixtures::uuid();
    DB::table('prc_process_outbox')->insert([
        'message_id' => $message->value,
        'dedup_key' => 'feed:acquisition:unsupported-idle',
        'kind' => 'feeder_wake',
        'payload' => '{}',
        'payload_schema_version' => 2,
        'available_at' => DB::raw('clock_timestamp()'),
    ]);
    $daemon = app(FeederDaemon::class);
    expect($daemon->tick()->errorCode)->toBe('outbox_quarantined');
    for ($poll = 0; $poll < 3; $poll++) {
        $tick = $daemon->tick();
        expect($tick->processed)->toBe(0)->and($tick->errorCode)->toBe('outbox_quarantined');
    }
    expect(DB::table('prc_process_outbox')->where('message_id', $message->value)->value('attempts'))->toBe(1);
    DB::table('prc_process_outbox')->where('message_id', $message->value)->update(['published_at' => DB::raw('clock_timestamp()')]);
    expect($daemon->tick()->errorCode)->toBeNull();
});

test('ошибка producer не останавливает здоровый producer, admission listing и подтверждение wake', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    app()->instance(ProcessClock::class, loopClock());
    $work = ExecutionFixtures::listingWork();
    $message = LedgerFixtures::uuid();
    DB::table('prc_process_outbox')->insert([
        'message_id' => $message->value,
        'dedup_key' => 'feed:fixture:producer-isolation',
        'kind' => 'feeder_wake',
        'payload' => '{"lane_key":"acquisition"}',
        'payload_schema_version' => 1,
        'available_at' => DB::raw('clock_timestamp()'),
    ]);
    $failure = new RuntimeException('fixture producer failure');
    $failing = new class($failure) implements ReconcileCategory
    {
        public bool $fails = true;

        public function __construct(private readonly Throwable $failure) {}

        public function name(): string
        {
            return 'fixture_failing';
        }

        public function reconcile(): int
        {
            if ($this->fails) {
                throw $this->failure;
            }

            return 0;
        }
    };
    $healthy = new class implements ReconcileCategory
    {
        public int $calls = 0;

        public function name(): string
        {
            return 'fixture_healthy';
        }

        public function reconcile(): int
        {
            $this->calls++;

            return 1;
        }
    };
    $laterFailing = new class implements ReconcileCategory
    {
        public bool $fails = true;

        public function name(): string
        {
            return 'fixture_later_failing';
        }

        public function reconcile(): int
        {
            if ($this->fails) {
                throw new LogicException('fixture later producer failure');
            }

            return 0;
        }
    };
    $daemon = app(FeederDaemon::class);
    $daemon->registerProducer($failing);
    $daemon->registerProducer($laterFailing);
    $daemon->registerProducer($healthy);

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $caught = null;
        try {
            $daemon->tick();
        } catch (Throwable $thrown) {
            $caught = $thrown;
        }
        $wake = DB::table('prc_process_outbox')->where('message_id', $message->value)->sole();
        expect($caught)->toBe($failure)
            ->and($healthy->calls)->toBe($attempt)
            ->and(ExecutionFixtures::work($work)->state)->toBe('admitted')
            ->and(DB::table('prc_admission_groups')->where('work_id', $work->value->value)->count())->toBe(1)
            ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->where('payload->work_id', $work->value->value)->count())->toBe(1)
            ->and($wake->published_at)->not->toBeNull()
            ->and($wake->claim_token)->toBeNull()
            ->and($wake->claim_until)->toBeNull()
            ->and($wake->last_error_code)->toBeNull()
            ->and($wake->attempts)->toBe(1);
    }

    $failing->fails = false;
    $laterFailing->fails = false;
    $tick = $daemon->tick();
    $wake = DB::table('prc_process_outbox')->where('message_id', $message->value)->sole();
    expect($tick->errorCode)->toBeNull()
        ->and($tick->processed)->toBeGreaterThanOrEqual(1)
        ->and($healthy->calls)->toBe(3)
        ->and($wake->published_at)->not->toBeNull()
        ->and($wake->last_error_code)->toBeNull()
        ->and(DB::table('prc_admission_groups')->where('work_id', $work->value->value)->count())->toBe(1)
        ->and($daemon->tick()->processed)->toBe(0)
        ->and($healthy->calls)->toBe(3);
});

test('ошибка первой lane сохраняет wake и не мешает admission готовой работы в следующей lane', function (): void {
    ExecutionFixtures::authority([StageCode::Listing, StageCode::Prepare]);
    $listing = ExecutionFixtures::listingWork();
    $version = DocumentVersion::factory()->create();
    $prepare = ExecutionFixtures::store(
        input: new PrepareInput(documentVersionId: $version->id, preparationRecipeHash: app(PreparePolicy::class)->hash),
        cohort: 'manual',
        priority: 100,
    );
    app()->instance(ProcessClock::class, loopClock());
    $message = LedgerFixtures::uuid();
    DB::table('prc_process_outbox')->insert([
        'message_id' => $message->value,
        'dedup_key' => 'feed:fixture:lane-isolation',
        'kind' => 'feeder_wake',
        'payload' => '{"lane_key":"acquisition"}',
        'payload_schema_version' => 1,
        'available_at' => DB::raw('clock_timestamp()'),
    ]);
    $failure = new RuntimeException('fixture acquisition lane failure');
    $armed = true;
    DB::beforeExecuting(static function (string $query, array $bindings) use (&$armed, $failure): void {
        if ($armed && str_contains($query, 'FROM prc_process_lanes l WHERE') && in_array('acquisition', $bindings, true)) {
            throw $failure;
        }
    });
    $daemon = app(FeederDaemon::class);
    $caught = null;
    try {
        $daemon->tick();
    } catch (Throwable $thrown) {
        $caught = $thrown;
    } finally {
        $armed = false;
    }
    $wake = DB::table('prc_process_outbox')->where('message_id', $message->value)->sole();
    expect($caught)->toBe($failure)
        ->and(ExecutionFixtures::work($listing)->state)->toBe('ready')
        ->and(ExecutionFixtures::work($prepare)->state)->toBe('admitted')
        ->and(DB::table('prc_admission_groups')->where('work_id', $prepare->value->value)->count())->toBe(1)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->where('payload->work_id', $prepare->value->value)->count())->toBe(1)
        ->and($wake->published_at)->toBeNull()
        ->and($wake->claim_token)->toBeNull()
        ->and($wake->claim_until)->toBeNull()
        ->and($wake->last_error_code)->toBe('feed_tick_failed');

    $tick = $daemon->tick();
    expect($tick->errorCode)->toBeNull()
        ->and($tick->processed)->toBeGreaterThanOrEqual(2)
        ->and(ExecutionFixtures::work($listing)->state)->toBe('admitted')
        ->and(DB::table('prc_admission_groups')->where('work_id', $listing->value->value)->count())->toBe(1)
        ->and(DB::table('prc_admission_groups')->where('work_id', $prepare->value->value)->count())->toBe(1)
        ->and(DB::table('prc_process_outbox')->where('message_id', $message->value)->value('published_at'))->not->toBeNull()
        ->and(DB::table('prc_process_outbox')->where('message_id', $message->value)->value('last_error_code'))->toBeNull();
});

test('heartbeat readiness: свежий instance без ошибки — healthy, с ошибкой — degraded, молчащий — down, retire убирает instance', function (): void {
    $heartbeats = app(DaemonHeartbeatRepository::class);
    $readiness = app(DaemonReadiness::class);
    $instance = LedgerFixtures::uuid();

    expect($readiness->of(DaemonKind::Relay))->toBe(DaemonHealth::Down);

    $heartbeats->register($instance, DaemonKind::Relay);
    expect($readiness->of(DaemonKind::Relay))->toBe(DaemonHealth::Healthy);

    $heartbeats->beat($instance, 'redis_publish_failed');
    expect($readiness->of(DaemonKind::Relay))->toBe(DaemonHealth::Degraded)
        ->and(DB::table('prc_process_daemon_heartbeats')->where('instance_id', $instance->value)->value('last_error_code'))->toBe('redis_publish_failed');

    $heartbeats->beat($instance, null);
    expect($readiness->of(DaemonKind::Relay))->toBe(DaemonHealth::Healthy);

    // Сравнение со сроком выполняет SQL на DB clock: 31 s тишины > live window 30 s.
    DB::update("UPDATE prc_process_daemon_heartbeats SET last_tick_at = clock_timestamp() - interval '31 seconds' WHERE instance_id = ?", [$instance->value]);
    expect($readiness->of(DaemonKind::Relay))->toBe(DaemonHealth::Down);

    DB::update("UPDATE prc_process_daemon_heartbeats SET last_tick_at = clock_timestamp() - interval '29 seconds' WHERE instance_id = ?", [$instance->value]);
    expect($readiness->of(DaemonKind::Relay))->toBe(DaemonHealth::Healthy);

    $heartbeats->retire($instance);
    expect($readiness->of(DaemonKind::Relay))->toBe(DaemonHealth::Down)
        ->and($heartbeats->beat($instance, null))->toBeFalse();
});

test('readiness требует по живому instance КАЖДОГО активированного daemon, включая project', function (): void {
    $heartbeats = app(DaemonHeartbeatRepository::class);
    $readiness = app(DaemonReadiness::class);
    $instances = [];
    foreach ([DaemonKind::Relay, DaemonKind::Reconcile, DaemonKind::Feed] as $kind) {
        $instances[$kind->value] = LedgerFixtures::uuid();
        $heartbeats->register($instances[$kind->value], $kind);
    }

    // Без живого projector readiness красный: он больше не «будущий» daemon.
    expect(array_keys($readiness->all()))->toBe(['relay', 'reconcile', 'feed', 'project'])
        ->and($readiness->ready())->toBeFalse();

    $heartbeats->register(LedgerFixtures::uuid(), DaemonKind::Project);
    expect($readiness->ready())->toBeTrue()
        ->and(DaemonKind::Project->activated())->toBeTrue()
        ->and(DaemonKind::activeKinds())->toBe([DaemonKind::Relay, DaemonKind::Reconcile, DaemonKind::Feed, DaemonKind::Project]);

    // Один instance второго экземпляра той же роли не заменяет упавший: пока жив хоть один без ошибки — healthy.
    $second = LedgerFixtures::uuid();
    $heartbeats->register($second, DaemonKind::Relay);
    $heartbeats->beat($second, 'redis_publish_failed');
    expect($readiness->of(DaemonKind::Relay))->toBe(DaemonHealth::Healthy);
    $heartbeats->beat($instances['relay'], 'redis_publish_failed');
    expect($readiness->of(DaemonKind::Relay))->toBe(DaemonHealth::Degraded);
});

test('loop: ошибка tick не убивает loop и не выдаётся за успех — backoff 1→2 s, degraded heartbeat, восстановление, retire при выходе', function (): void {
    $clock = loopClock();
    $seen = [];
    $daemon = loopProbe(DaemonKind::Relay, function (object $self) use (&$seen): DaemonTick {
        $seen[] = DB::table('prc_process_daemon_heartbeats')->value('last_error_code');
        if ($self->ticks <= 2) {
            throw new RuntimeException('tick exploded token=secret');
        }

        return new DaemonTick(1);
    });

    $reason = loopFor($clock)->run($daemon, LedgerFixtures::uuid(), fn (): bool => $daemon->ticks >= 4);

    expect($reason)->toBe(DaemonLoop::STOP_SIGNAL)
        ->and($seen)->toBe([null, 'tick_failed', 'tick_failed', null])
        ->and(array_sum($clock->sleeps))->toBe(3000)
        ->and(max($clock->sleeps))->toBeLessThanOrEqual(250)
        ->and(DB::table('prc_process_daemon_heartbeats')->count())->toBe(0);
});

test('loop восстанавливает отсутствующий heartbeat и сохраняет текущую ошибку до чистого tick', function (bool $registrationFailed): void {
    $instance = LedgerFixtures::uuid();
    $armed = $registrationFailed;
    $registeredErrors = [];
    DB::beforeExecuting(function (string $query) use (&$armed): void {
        if ($armed && str_starts_with($query, 'INSERT INTO prc_process_daemon_heartbeats')) {
            $armed = false;

            throw new RuntimeException('Initial database connection unavailable.');
        }
    });
    DB::listen(function (QueryExecuted $query) use ($instance, &$registeredErrors): void {
        if (str_starts_with($query->sql, 'INSERT INTO prc_process_daemon_heartbeats')) {
            // A readiness reader can observe the row as soon as registration commits.
            $registeredErrors[] = DB::table('prc_process_daemon_heartbeats')->where('instance_id', $instance->value)->value('last_error_code');
        }
    });
    $seen = null;
    $daemon = loopProbe(DaemonKind::Relay, function (object $self) use ($instance, $registrationFailed, &$seen): DaemonTick {
        if ($self->ticks === 1) {
            if (! $registrationFailed) {
                DB::table('prc_process_daemon_heartbeats')->where('instance_id', $instance->value)->delete();
            }

            throw new RuntimeException('Tick failed while the heartbeat row was missing.');
        }
        $seen = [app(DaemonReadiness::class)->of(DaemonKind::Relay), DB::table('prc_process_daemon_heartbeats')->value('last_error_code')];

        return new DaemonTick(1);
    });

    expect(loopFor(loopClock())->run($daemon, $instance, fn (): bool => $daemon->ticks >= 2))->toBe(DaemonLoop::STOP_SIGNAL)
        ->and($seen)->toBe([DaemonHealth::Degraded, 'tick_failed'])
        ->and($registeredErrors)->toBe($registrationFailed ? ['tick_failed'] : [null, 'tick_failed'])
        ->and(DB::table('prc_process_daemon_heartbeats')->count())->toBe(0);
})->with(['startup database outage' => [true], 'heartbeat removed while running' => [false]]);

test('loop: heartbeat идёт каждые 10 s и во время длинного backoff, а не только после tick', function (): void {
    $clock = loopClock();
    $start = $clock->now;
    $beats = 0;
    DB::listen(function (QueryExecuted $query) use (&$beats): void {
        if (str_starts_with($query->sql, 'UPDATE prc_process_daemon_heartbeats SET last_tick_at')) {
            $beats++;
        }
    });
    $daemon = loopProbe(DaemonKind::Feed, static fn () => throw new RuntimeException('persistent failure'));

    loopFor($clock)->run($daemon, LedgerFixtures::uuid(), fn (): bool => $clock->now - $start >= 40_000_000_000);

    // Первый beat — смена кода ошибки, далее каждые 10 s: ≥4 за 40 s тишины между tick.
    expect($beats)->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(8)
        ->and($daemon->ticks)->toBeLessThan(12);
});

test('loop: max runtime и memory limit завершают процесс для рестарта docker, сигнал — graceful stop', function (): void {
    $clock = loopClock();
    config()->set('process.daemons.max_runtime_seconds', 3);
    $daemon = loopProbe(DaemonKind::Reconcile, static fn () => new DaemonTick(0), delayMs: 1000);

    expect(loopFor($clock)->run($daemon, LedgerFixtures::uuid(), fn (): bool => false))->toBe(DaemonLoop::STOP_MAX_RUNTIME)
        ->and($daemon->ticks)->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(4);

    $memory = loopProbe(DaemonKind::Reconcile, static fn () => new DaemonTick(0));
    expect(loopFor(loopClock(), static fn (): int => 512 * 1024 * 1024)->run($memory, LedgerFixtures::uuid(), fn (): bool => false))->toBe(DaemonLoop::STOP_MEMORY)
        ->and($memory->ticks)->toBe(0);

    $stopped = loopProbe(DaemonKind::Reconcile, static fn () => new DaemonTick(0));
    expect(loopFor(loopClock())->run($stopped, LedgerFixtures::uuid(), fn (): bool => true))->toBe(DaemonLoop::STOP_SIGNAL)
        ->and($stopped->ticks)->toBe(0)
        ->and(DB::table('prc_process_daemon_heartbeats')->count())->toBe(0);
});

test('relay при недоступном Redis: liveness свежий, readiness degraded с кодом redis_publish_failed', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    ExecutionFixtures::listingWork();
    app(WorkFeeder::class)->tick('acquisition');
    app()->instance(WorkWakePublisher::class, new class implements WorkWakePublisher
    {
        public function publish(WorkWake $wake): void
        {
            throw new RuntimeException('connection refused password=secret');
        }
    });
    $calls = 0;
    $seen = null;

    loopFor(loopClock())->run(app(RelayDaemon::class), LedgerFixtures::uuid(), function () use (&$calls, &$seen): bool {
        if (++$calls < 2) {
            return false;
        }
        $seen = [app(DaemonReadiness::class)->of(DaemonKind::Relay), DB::table('prc_process_daemon_heartbeats')->value('last_error_code')];

        return true;
    });

    expect($seen)->toBe([DaemonHealth::Degraded, 'redis_publish_failed'])
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->whereNull('published_at')->count())->toBe(1);
});

test('reconcile daemon: категории изолированы, сбой одной — degraded код, дубликат имени и пустой набор честны', function (): void {
    $daemon = new ReconcileDaemon(app(DaemonPolicy::class));

    expect($daemon->kind())->toBe(DaemonKind::Reconcile)
        ->and($daemon->categories())->toBe([])
        ->and($daemon->tick())->toEqual(DaemonTick::idle());

    $make = static fn (string $name, int|Throwable $result): ReconcileCategory => new class($name, $result) implements ReconcileCategory
    {
        public function __construct(private readonly string $name, private readonly int|Throwable $result) {}

        public function name(): string
        {
            return $this->name;
        }

        public function reconcile(): int
        {
            return $this->result instanceof Throwable ? throw $this->result : $this->result;
        }
    };
    $daemon->register($make('expired_running', new RuntimeException('category failed')));
    $daemon->register($make('expired_admitted', 5));
    $tick = $daemon->tick();

    expect($daemon->categories())->toBe(['expired_running', 'expired_admitted'])
        ->and([$tick->processed, $tick->errorCode])->toBe([5, 'reconcile_category_failed'])
        ->and(fn () => $daemon->register($make('expired_admitted', 1)))->toThrow(LogicException::class, 'already registered');

    // tick 5 s ±20%: delay не синхронизируется между instances и не выходит за границы.
    $delays = array_map(static fn (): int => $daemon->delayMs($tick), range(1, 50));
    expect(min($delays))->toBeGreaterThanOrEqual(4000)->and(max($delays))->toBeLessThanOrEqual(6000)->and(count(array_unique($delays)))->toBeGreaterThan(1);
});

test('DaemonPolicy boot-validated: противоречивый config отвергается с ключом, backoff 1→30 s', function (): void {
    $policy = new DaemonPolicy(app('config'));

    expect(array_map($policy->backoffMs(...), [1, 2, 3, 4, 5, 6, 7, 40]))->toBe([1000, 2000, 4000, 8000, 16000, 30000, 30000, 30000])
        ->and([$policy->heartbeatSeconds, $policy->liveSeconds, $policy->relayBatch])->toBe([10, 30, 100]);

    config()->set('process.daemons.live_seconds', 15);
    expect(fn () => new DaemonPolicy(app('config')))->toThrow(InvalidArgumentException::class, 'Invalid daemon policy: process.daemons.live_seconds');
    config()->set('process.daemons.live_seconds', 30);

    config()->set('process.daemons.relay.idle_max_ms', 100);
    expect(fn () => new DaemonPolicy(app('config')))->toThrow(InvalidArgumentException::class, 'Invalid daemon policy: process.daemons.relay.idle_max_ms');
    config()->set('process.daemons.relay.idle_max_ms', 500);

    config()->set('process.daemons.feed.unknown', 1);
    expect(fn () => new DaemonPolicy(app('config')))->toThrow(InvalidArgumentException::class, 'Invalid daemon policy: process.daemons.feed');
});

test('process:daemon: неизвестный daemon не запускается, --health отражает readiness exit-кодом (project активирован)', function (): void {
    $this->artisan('process:daemon', ['daemon' => 'nonsense'])->assertExitCode(2);
    // Без живого instance projector health красный (down); сам daemon в тесте не запускается — это бесконечный loop.
    $this->artisan('process:daemon', ['daemon' => 'project', '--health' => true])->assertExitCode(1);

    $this->artisan('process:daemon', ['daemon' => 'relay', '--health' => true])->assertExitCode(1);
    $instance = LedgerFixtures::uuid();
    app(DaemonHeartbeatRepository::class)->register($instance, DaemonKind::Relay);
    $this->artisan('process:daemon', ['daemon' => 'relay', '--health' => true])->assertExitCode(0);
    app(DaemonHeartbeatRepository::class)->beat($instance, 'outbox_quarantined');
    $this->artisan('process:daemon', ['daemon' => 'relay', '--health' => true])->assertExitCode(1);
});

test('health каждой активированной роли использует DB heartbeat и не путает свежую ошибку с готовностью', function (DaemonKind $kind): void {
    $heartbeats = app(DaemonHeartbeatRepository::class);
    $readiness = app(DaemonReadiness::class);
    $this->artisan('process:daemon', ['daemon' => $kind->value, '--health' => true])
        ->expectsOutput($kind->value.': down')->assertExitCode(1);

    $instance = LedgerFixtures::uuid();
    $heartbeats->register($instance, $kind);
    expect($readiness->of($kind))->toBe(DaemonHealth::Healthy);
    $this->artisan('process:daemon', ['daemon' => $kind->value, '--health' => true])
        ->expectsOutput($kind->value.': healthy')->assertExitCode(0);

    DB::table('prc_process_daemon_heartbeats')->where('instance_id', $instance->value)
        ->update(['last_tick_at' => DB::raw("clock_timestamp() - interval '31 seconds'")]);
    expect($readiness->of($kind))->toBe(DaemonHealth::Down);
    $this->artisan('process:daemon', ['daemon' => $kind->value, '--health' => true])
        ->expectsOutput($kind->value.': down')->assertExitCode(1);

    $heartbeats->beat($instance, 'tick_failed');
    $heartbeats->beat($instance, 'tick_failed');
    expect($readiness->of($kind))->toBe(DaemonHealth::Degraded);
    $this->artisan('process:daemon', ['daemon' => $kind->value, '--health' => true])
        ->expectsOutput($kind->value.': degraded')->assertExitCode(1);

    $heartbeats->beat($instance, null);
    expect($readiness->of($kind))->toBe(DaemonHealth::Healthy);
    $this->artisan('process:daemon', ['daemon' => $kind->value, '--health' => true])
        ->expectsOutput($kind->value.': healthy')->assertExitCode(0);
})->with([DaemonKind::Relay, DaemonKind::Reconcile, DaemonKind::Feed]);
