<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\CheckpointResult;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\LeaseRenewal;
use App\Dto\Procurement\Process\NoProviderRecovery;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\CheckpointStatus;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Exceptions\Procurement\Process\SliceExhausted;
use App\Services\Procurement\Process\Contracts\LeaseKeeper;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\ExecutionContextFactory;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t25', 'p18');

/** Fake monotonic/DB clock: sleep продвигает время, без реального ожидания. */
function contextClock(): ProcessClock
{
    return new class implements ProcessClock
    {
        public int $now = 1_000_000_000;

        /** @var list<int> */
        public array $sleeps = [];

        public function dbNow(): DbInstant
        {
            return new DbInstant((new DateTimeImmutable('2026-10-26T00:00:00Z'))->modify('+'.intdiv($this->now, 1_000_000_000).' seconds'));
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

        public function advance(int $seconds): void
        {
            $this->now += $seconds * 1_000_000_000;
        }
    };
}

/** Fake DB-порт: считает heartbeat CAS и управляемо теряет ownership или DB. */
function contextKeeper(): LeaseKeeper
{
    return new class implements LeaseKeeper
    {
        public int $heartbeats = 0;

        public bool $lose = false;

        public bool $databaseDown = false;

        public ?CheckpointStatus $checkpointRefusal = null;

        public function heartbeat(ExecutionLease $lease): LeaseRenewal
        {
            if ($this->databaseDown) {
                throw new DependencyUnavailable('database');
            }
            $this->heartbeats++;

            return $this->lose ? LeaseRenewal::lost() : LeaseRenewal::renewed(new DbInstant(new DateTimeImmutable('2026-10-26T01:00:00Z')));
        }

        public function checkpoint(ExecutionLease $lease, StageCheckpoint $checkpoint, int $expectedSeq): CheckpointResult
        {
            if ($this->checkpointRefusal !== null) {
                return CheckpointResult::refused($this->checkpointRefusal);
            }

            return CheckpointResult::saved($expectedSeq + 1, new DbInstant(new DateTimeImmutable('2026-10-26T01:00:00Z')), new WorkCheckpointEnvelope($checkpoint, new NoProviderRecovery));
        }
    };
}

function contextLease(StageCode $stage = StageCode::Detail): ExecutionLease
{
    return new ExecutionLease(
        new WorkId(LedgerFixtures::uuid()), LedgerFixtures::uuid(), 3, LedgerFixtures::uuid(), new DateTimeImmutable('2026-10-26T00:03:00Z'),
        1, new ContentHash(str_repeat('a', 64)), 0, null, $stage, 'fixture-detail/1',
    );
}

/** Short lane bounds execution#lane-defaults: slice 90, lease 180, heartbeat 15, reserve 15, safety 30. */
function contextFor(LeaseKeeper $keeper, ProcessClock $clock): ExecutionContext
{
    return new ExecutionContext(contextLease(), $keeper, $clock, Duration::seconds(90), Duration::seconds(180), Duration::seconds(15), Duration::seconds(15), Duration::seconds(30));
}

test('T25 active 45 s: tick между частями продлевает DB lease каждые 15 s monotonic, не чаще', function (): void {
    [$keeper, $clock] = [contextKeeper(), contextClock()];
    $context = contextFor($keeper, $clock);

    foreach (range(1, 45) as $ignored) {
        $clock->advance(1);
        $context->tick();
    }

    expect($keeper->heartbeats)->toBe(3)
        ->and($context->lease()->leaseUntil->format(DATE_ATOM))->toBe('2026-10-26T01:00:00+00:00')
        ->and($context->remainingSlice()->milliseconds)->toBe(45_000)
        ->and($context->ids()->generation)->toBe(3);
});

test('T25 idle progress callback тикает в ожидании; lost heartbeat останавливает работу без повторного CAS', function (): void {
    [$keeper, $clock] = [contextKeeper(), contextClock()];
    $context = contextFor($keeper, $clock);
    $progress = $context->progressCallback();

    $clock->advance(16);
    $progress(0, 0, 0, 0);
    expect($keeper->heartbeats)->toBe(1);

    $keeper->lose = true;
    $clock->advance(16);
    expect(fn () => $progress(100, 0, 0, 0))->toThrow(LeaseLost::class)
        ->and(fn () => $context->tick())->toThrow(LeaseLost::class)
        ->and($keeper->heartbeats)->toBe(2)
        ->and($context->canStart(Duration::seconds(1)))->toBeFalse()
        ->and(fn () => $context->checkpoint(new FixtureStageCheckpoint(1, StageCode::Detail)))->toThrow(LeaseLost::class);
});

test('T25 slice: canStart требует полный timeout плюс finalization reserve, исчерпание — SliceExhausted без потери lease', function (): void {
    [$keeper, $clock] = [contextKeeper(), contextClock()];
    $context = contextFor($keeper, $clock);
    $clock->advance(40);
    $context->tick();

    expect($context->remainingSlice()->milliseconds)->toBe(50_000)
        ->and($context->canStart(Duration::seconds(35)))->toBeTrue()
        ->and($context->canStart(Duration::seconds(36)))->toBeFalse();

    $clock->advance(50);
    expect(fn () => $context->tick())->toThrow(SliceExhausted::class)
        ->and($context->remainingSlice()->milliseconds)->toBe(0);
});

test('T25 DB unavailable: typed DependencyUnavailable, локальный safety deadline останавливает работу без DB authorization', function (): void {
    [$keeper, $clock] = [contextKeeper(), contextClock()];
    $context = new ExecutionContext(contextLease(), $keeper, $clock, Duration::seconds(300), Duration::seconds(180), Duration::seconds(15), Duration::seconds(15), Duration::seconds(30));
    $keeper->databaseDown = true;

    $clock->advance(16);
    expect(fn () => $context->tick())->toThrow(DependencyUnavailable::class)
        ->and($context->canStart(Duration::seconds(100)))->toBeTrue()
        ->and($context->canStart(Duration::seconds(140)))->toBeFalse();

    // last renewal + lease 180 − safety 30: дальше ни tick, ни новый effect.
    $clock->advance(134);
    expect(fn () => $context->tick())->toThrow(LeaseLost::class)
        ->and($context->canStart(Duration::seconds(1)))->toBeFalse()
        ->and($keeper->heartbeats)->toBe(0);
});

test('T25 sleepUntil спит частями ≤5 s с tick между ними; срок за пределами slice — SliceExhausted без ожидания', function (): void {
    [$keeper, $clock] = [contextKeeper(), contextClock()];
    $context = contextFor($keeper, $clock);

    $context->sleepUntil(new DbInstant($clock->dbNow()->value->modify('+17 seconds')));
    expect($clock->sleeps)->toBe([5000, 5000, 5000, 2000])
        ->and($keeper->heartbeats)->toBe(1);

    $clock->sleeps = [];
    expect(fn () => $context->sleepUntil(new DbInstant($clock->dbNow()->value->modify('+80 seconds'))))->toThrow(SliceExhausted::class)
        ->and($clock->sleeps)->toBe([]);
});

test('T25 checkpoint через context продвигает seq lease и renewal; отказ CAS — LeaseLost', function (): void {
    [$keeper, $clock] = [contextKeeper(), contextClock()];
    $context = contextFor($keeper, $clock);

    $saved = $context->checkpoint(new FixtureStageCheckpoint(4, StageCode::Detail));
    expect([$saved->status, $context->lease()->checkpointSeq])->toBe([CheckpointStatus::Saved, 1])
        ->and($context->lease()->checkpoint?->stageProgress)->toEqual(new FixtureStageCheckpoint(4, StageCode::Detail))
        ->and(fn () => $context->checkpoint(new FixtureStageCheckpoint(5)))->toThrow(InvalidArgumentException::class);

    $keeper->checkpointRefusal = CheckpointStatus::InputSuperseded;
    expect(fn () => $context->checkpoint(new FixtureStageCheckpoint(6, StageCode::Detail)))->toThrow(LeaseLost::class)
        ->and(fn () => $context->tick())->toThrow(LeaseLost::class);
});

test('T25 factory открывает context по timing policy stage; lease обязан пережить два heartbeat и safety', function (): void {
    app()->instance(LeaseKeeper::class, contextKeeper());
    app()->instance(ProcessClock::class, contextClock());

    expect(app(ExecutionContextFactory::class)->open(contextLease())->remainingSlice()->milliseconds)->toBe(90_000)
        ->and(app(ExecutionContextFactory::class)->open(contextLease(StageCode::Download))->remainingSlice()->milliseconds)->toBe(300_000)
        ->and(fn () => new ExecutionContext(contextLease(), contextKeeper(), contextClock(), Duration::seconds(90), Duration::seconds(60), Duration::seconds(15), Duration::seconds(15), Duration::seconds(30)))
        ->toThrow(InvalidArgumentException::class);
});
