<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\DeliveryEnvelope;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\DeliveryOutcome;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Exceptions\Procurement\Process\SliceExhausted;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('delivery-and-daemon-lifecycle', 'claim-recovery-and-invariants');

/**
 * Fixture handler listing: authority rows, finalization binding и handler instance этого PHP процесса.
 * Поведение задаёт тест; считает вызовы (ноль вызовов — нет effects).
 */
function runnerHandler(): object
{
    FinalizeFixtures::authority([StageCode::Listing]);
    $handler = new class implements StageHandler
    {
        public int $calls = 0;

        public ?Closure $behavior = null;

        public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
        {
            $this->calls++;

            return $this->behavior !== null
                ? ($this->behavior)($input, $execution)
                : FinalizeFixtures::succeeded(StageCode::Listing, 'delivered');
        }
    };
    app(StageRegistry::class)->bindHandler(StageCode::Listing, $handler);

    return $handler;
}

/** Admitted listing work (после runnerHandler): реальный feeder tick выдаёт admission и work_wake. */
function runnerAdmitted(): WorkId
{
    $work = ExecutionFixtures::listingWork();
    app(WorkFeeder::class)->tick('acquisition');

    return $work;
}

function runnerEnvelope(WorkId $work, ?int $generation = null, int $schema = 1): DeliveryEnvelope
{
    return DeliveryEnvelope::fromScalars($work->value->value, $generation ?? ExecutionFixtures::delivery($work)->value, LedgerFixtures::uuid()->value, $schema);
}

function runnerWork(WorkId $work): object
{
    return DB::table('prc_process_work')->where('id', $work->value->value)->first(['state', 'reason_code', 'generation', 'item_failures', 'checkpoint_seq', 'due_at']);
}

test('claimed delivery исполняется ровно один раз: handler, единственный finalizer, succeeded; повтор той же delivery ACK без handler', function (): void {
    $handler = runnerHandler();
    $work = runnerAdmitted();
    $envelope = runnerEnvelope($work);

    expect(app(WorkRunner::class)->runDelivery($envelope))->toBe(DeliveryOutcome::Executed)
        ->and($handler->calls)->toBe(1)
        ->and(runnerWork($work)->state)->toBe('succeeded');

    $attempts = DB::table('prc_process_attempts')->where('work_id', $work->value->value)->count();
    expect(app(WorkRunner::class)->runDelivery($envelope))->toBe(DeliveryOutcome::Rejected)
        ->and($handler->calls)->toBe(1)
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->count())->toBe($attempts)
        ->and(runnerWork($work)->state)->toBe('succeeded');
});

test('stale dispatch generation не вызывает handler и не меняет work', function (): void {
    $handler = runnerHandler();
    $work = runnerAdmitted();

    expect(app(WorkRunner::class)->runDelivery(runnerEnvelope($work, generation: 99)))->toBe(DeliveryOutcome::Rejected)
        ->and($handler->calls)->toBe(0)
        ->and(runnerWork($work)->state)->toBe('admitted')
        ->and(runnerWork($work)->generation)->toBe(0);
});

test('неподдерживаемая queue schema не исполняется: incident quarantine, work нетронута для scanners', function (): void {
    $handler = runnerHandler();
    $work = runnerAdmitted();

    expect(app(WorkRunner::class)->runDelivery(runnerEnvelope($work, schema: 2)))->toBe(DeliveryOutcome::Quarantined)
        ->and($handler->calls)->toBe(0)
        ->and(runnerWork($work)->state)->toBe('admitted')
        ->and(DB::table('prc_process_incidents')->where('kind', 'queue_schema_unsupported')->where('state', 'open')->count())->toBe(1);

    // Повторная доставка той же неподдерживаемой версии не множит incident, а обновляет occurrences.
    app(WorkRunner::class)->runDelivery(runnerEnvelope($work, schema: 2));
    $incident = DB::table('prc_process_incidents')->where('kind', 'queue_schema_unsupported')->sole();
    expect($incident->occurrences)->toBe(2);
});

test('неожиданный programming Throwable идёт в locked handler-fault budget: deferred(handler_fault), item budget цел', function (): void {
    $handler = runnerHandler();
    $handler->behavior = static fn () => throw new LogicException('boom password=secret');
    $work = runnerAdmitted();

    expect(app(WorkRunner::class)->runDelivery(runnerEnvelope($work)))->toBe(DeliveryOutcome::Executed);
    $row = runnerWork($work);
    $attempt = DB::table('prc_process_attempts')->where('work_id', $work->value->value)->sole();

    expect([$row->state, $row->reason_code, $row->item_failures])->toBe(['deferred', 'handler_fault', 0])
        ->and($attempt->fault_signature)->not->toBeNull()
        ->and($attempt->terminal_kind)->not->toBeNull()
        ->and(DB::selectOne('SELECT extract(epoch FROM due_at - clock_timestamp()) AS due_in FROM prc_process_work WHERE id = ?', [$work->value->value])->due_in)
        ->toBeGreaterThan(50.0)->toBeLessThanOrEqual(66.0);
});

test('LeaseLost прекращает delivery без публикации и finalize: work остаётся durable running для recovery', function (): void {
    $handler = runnerHandler();
    $work = runnerAdmitted();
    $handler->behavior = static fn () => throw LeaseLost::forWork($work->value->value);

    expect(app(WorkRunner::class)->runDelivery(runnerEnvelope($work)))->toBe(DeliveryOutcome::LeaseLost)
        ->and(runnerWork($work)->state)->toBe('running')
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->whereNull('finished_at')->count())->toBe(1)
        ->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(0);
});

test('SliceExhausted без checkpoint — deferred(slice_exhausted), с устойчивым checkpoint — Partial ready; item budget не тратится', function (): void {
    $handler = runnerHandler();
    $handler->behavior = static fn (WorkInput $input, ExecutionContext $execution) => throw SliceExhausted::forWork($execution->lease()->workId->value->value);
    $bare = runnerAdmitted();
    expect(app(WorkRunner::class)->runDelivery(runnerEnvelope($bare)))->toBe(DeliveryOutcome::Executed);
    $deferred = runnerWork($bare);

    $handler->behavior = static function (WorkInput $input, ExecutionContext $execution): never {
        $execution->checkpoint(new FixtureStageCheckpoint(7));

        throw SliceExhausted::forWork($execution->lease()->workId->value->value);
    };
    $progress = ExecutionFixtures::listingWork();
    app(WorkFeeder::class)->tick('acquisition');
    expect(app(WorkRunner::class)->runDelivery(runnerEnvelope($progress)))->toBe(DeliveryOutcome::Executed);
    $partial = runnerWork($progress);

    expect([$deferred->state, $deferred->reason_code, $deferred->item_failures])->toBe(['deferred', 'slice_exhausted', 0])
        ->and([$partial->state, $partial->reason_code, $partial->due_at, $partial->item_failures])->toBe(['ready', null, null, 0])
        ->and($partial->checkpoint_seq)->toBeGreaterThanOrEqual(1)
        ->and(DB::table('prc_crawl_blocks')->whereRaw("cursor->>'position' = '7'")->count())->toBe(1);
});

test('typed dependency: storage → deferred(storage_unavailable); database → без finalize; неизвестная зависимость — дефект кода', function (): void {
    $handler = runnerHandler();
    $handler->behavior = static fn () => throw new DependencyUnavailable('storage');
    $storage = runnerAdmitted();
    expect(app(WorkRunner::class)->runDelivery(runnerEnvelope($storage)))->toBe(DeliveryOutcome::Executed);

    $handler->behavior = static fn () => throw new DependencyUnavailable('database');
    $database = ExecutionFixtures::listingWork();
    app(WorkFeeder::class)->tick('acquisition');
    expect(app(WorkRunner::class)->runDelivery(runnerEnvelope($database)))->toBe(DeliveryOutcome::DependencyUnavailable);

    $handler->behavior = static fn () => throw new DependencyUnavailable('mystery');
    $unknown = ExecutionFixtures::listingWork();
    app(WorkFeeder::class)->tick('acquisition');
    expect(app(WorkRunner::class)->runDelivery(runnerEnvelope($unknown)))->toBe(DeliveryOutcome::Executed);

    expect([runnerWork($storage)->state, runnerWork($storage)->reason_code, runnerWork($storage)->item_failures])->toBe(['deferred', 'storage_unavailable', 0])
        ->and(runnerWork($database)->state)->toBe('running')
        ->and([runnerWork($unknown)->state, runnerWork($unknown)->reason_code])->toBe(['deferred', 'handler_fault']);
});

/** Transient/нетранзиентная SQL ошибка на первом чтении claim: statement до БД не доходит. */
function runnerFailNextWorkRead(string $sqlState): void
{
    $armed = true;
    DB::beforeExecuting(function (string $query, array $bindings) use (&$armed, $sqlState): void {
        if (! $armed || ! str_contains($query, 'FROM prc_process_work')) {
            return;
        }
        $armed = false;
        $driver = new PDOException('simulated');
        $driver->errorInfo = [$sqlState, 7, 'simulated'];

        throw new QueryException('pgsql', $query, $bindings, $driver);
    });
}

test('transient SQL внутри handler не расходует fault budget и остаётся durable running для recovery', function (): void {
    $handler = runnerHandler();
    $handler->behavior = static function (): never {
        runnerFailNextWorkRead('08006');
        DB::selectOne('SELECT id FROM prc_process_work LIMIT 1');
        throw new LogicException('Expected injected database failure.');
    };
    $work = runnerAdmitted();

    expect(app(WorkRunner::class)->runDelivery(runnerEnvelope($work)))->toBe(DeliveryOutcome::DependencyUnavailable)
        ->and($handler->calls)->toBe(1)
        ->and([runnerWork($work)->state, runnerWork($work)->item_failures])->toBe(['running', 0]);
    $attempt = DB::table('prc_process_attempts')->where('work_id', $work->value->value)->sole();
    expect($attempt->finished_at)->toBeNull()
        ->and($attempt->fault_signature)->toBeNull()
        ->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(0);
});

test('job ACK-ит transient DB failure без исполнения, а нетранзиентную SQL ошибку пробрасывает видимо', function (): void {
    $handler = runnerHandler();
    $work = runnerAdmitted();
    $job = new ExecuteWorkJob($work->value->value, ExecutionFixtures::delivery($work)->value, LedgerFixtures::uuid()->value);

    runnerFailNextWorkRead('08006');
    app()->call([$job, 'handle']);
    expect($handler->calls)->toBe(0)->and(runnerWork($work)->state)->toBe('admitted');

    runnerFailNextWorkRead('42P01');
    expect(fn () => app()->call([$job, 'handle']))->toThrow(QueryException::class);
    expect($handler->calls)->toBe(0)->and(runnerWork($work)->state)->toBe('admitted');

    // После восстановления та же delivery исполняется: Redis redelivery не терялась.
    app()->call([$job, 'handle']);
    expect($handler->calls)->toBe(1)->and(runnerWork($work)->state)->toBe('succeeded');
});

test('job.failed только пишет telemetry и не меняет ledger', function (): void {
    runnerHandler();
    $work = runnerAdmitted();
    $before = [DB::table('prc_process_work')->count(), DB::table('prc_process_transitions')->count(), DB::table('prc_process_outbox')->count()];

    (new ExecuteWorkJob($work->value->value, 1, LedgerFixtures::uuid()->value))->failed(new RuntimeException('queue worker died'));
    (new ExecuteWorkJob($work->value->value, 1, LedgerFixtures::uuid()->value))->failed(null);

    expect([DB::table('prc_process_work')->count(), DB::table('prc_process_transitions')->count(), DB::table('prc_process_outbox')->count()])->toBe($before)
        ->and(runnerWork($work)->state)->toBe('admitted');
});
