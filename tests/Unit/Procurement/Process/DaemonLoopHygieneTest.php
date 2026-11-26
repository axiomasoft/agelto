<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\DaemonTick;
use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\DaemonKind;
use App\Repositories\Procurement\Process\DaemonHeartbeatRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\ProcessDaemon;
use App\Services\Procurement\Process\DaemonLoop;
use App\Services\Procurement\Process\DaemonPolicy;
use Illuminate\Support\Facades\DB;

test('лимит тиков политики необязателен и принимает только положительное число', function (): void {
    expect((new DaemonPolicy(app('config')))->maxTicks)->toBeNull()
        ->and((new DaemonPolicy(app('config'), maxTicks: 2))->maxTicks)->toBe(2)
        ->and(fn () => new DaemonPolicy(app('config'), maxTicks: 0))
        ->toThrow(InvalidArgumentException::class, 'Invalid daemon policy: max_ticks');
});

test('цикл очищает ошибки libxml и циклический мусор после каждого тика и выходит по лимиту', function (): void {
    DB::shouldReceive('delete')->twice()->andReturn(1);
    DB::shouldReceive('insert')->once()->andReturn(true);
    DB::shouldReceive('update')->once()->andReturn(1);

    $clock = new class implements ProcessClock
    {
        public int $sleeps = 0;

        public function dbNow(): DbInstant
        {
            return new DbInstant(new DateTimeImmutable('2026-10-26T00:00:00Z'));
        }

        public function monotonicNs(): int
        {
            return 0;
        }

        public function sleep(Duration $duration): void
        {
            $this->sleeps++;
        }
    };
    $probe = new stdClass;
    $probe->weak = null;
    $probe->parseErrors = [];
    $daemon = new class($probe) implements ProcessDaemon
    {
        public int $ticks = 0;

        public function __construct(private stdClass $probe) {}

        public function kind(): DaemonKind
        {
            return DaemonKind::Relay;
        }

        public function tick(?Closure $heartbeat = null): DaemonTick
        {
            $this->ticks++;
            if ($this->ticks === 2) {
                $this->probe->errorsBeforeSecond = count(libxml_get_errors());
                $this->probe->cycleCollected = $this->probe->weak->get() === null;
            }

            simplexml_load_string('<broken>');
            $this->probe->parseErrors[] = count(libxml_get_errors());

            if ($this->ticks === 2) {
                throw new RuntimeException('fixture tick failure');
            }

            $cycle = new stdClass;
            $cycle->self = $cycle;
            $this->probe->weak = WeakReference::create($cycle);

            return new DaemonTick(0);
        }

        public function delayMs(DaemonTick $last): int
        {
            return 1000;
        }
    };

    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    try {
        $loop = new DaemonLoop(
            clock: $clock,
            heartbeats: new DaemonHeartbeatRepository,
            policy: new DaemonPolicy(app('config'), maxTicks: 2),
            memoryUsage: static fn (): int => 0,
        );

        expect($loop->run($daemon, ProcessUuid::random(), static fn (): bool => false))->toBe(DaemonLoop::STOP_MAX_TICKS)
            ->and($daemon->ticks)->toBe(2)
            ->and($clock->sleeps)->toBe(4)
            ->and($probe->parseErrors[0])->toBeGreaterThan(0)
            ->and($probe->parseErrors[1])->toBeGreaterThan(0)
            ->and($probe->errorsBeforeSecond)->toBe(0)
            ->and($probe->cycleCollected)->toBeTrue()
            ->and(libxml_get_errors())->toBe([]);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
});

test('сигнал остановки сохраняет приоритет при достижении лимита тиков', function (): void {
    DB::shouldReceive('delete')->twice()->andReturn(1);
    DB::shouldReceive('insert')->once()->andReturn(true);

    $clock = Mockery::mock(ProcessClock::class);
    $clock->shouldReceive('monotonicNs')->andReturn(0);
    $daemon = Mockery::mock(ProcessDaemon::class);
    $daemon->shouldReceive('kind')->andReturn(DaemonKind::Relay);
    $ticks = 0;
    $daemon->shouldReceive('tick')->once()->andReturnUsing(function () use (&$ticks): DaemonTick {
        $ticks++;

        return new DaemonTick(0);
    });
    $daemon->shouldReceive('delayMs')->once()->andReturn(0);

    $loop = new DaemonLoop(
        clock: $clock,
        heartbeats: new DaemonHeartbeatRepository,
        policy: new DaemonPolicy(app('config'), maxTicks: 1),
        memoryUsage: static fn (): int => 0,
    );

    expect($loop->run($daemon, ProcessUuid::random(), static function () use (&$ticks): bool {
        return $ticks >= 1;
    }))->toBe(DaemonLoop::STOP_SIGNAL);
});
