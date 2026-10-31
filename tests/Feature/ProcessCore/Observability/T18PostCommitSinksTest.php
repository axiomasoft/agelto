<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\DeliveryEnvelope;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ProcessEventEnvelope;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\DeliveryOutcome;
use App\Enums\Procurement\Process\EventCode;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\Contracts\TransitionSink;
use App\Services\Procurement\Process\ExecutionCorrelation;
use App\Services\Procurement\Process\PostCommitSinks;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t18', 't19', 'p114');

/** Sink, записывающий принятые envelope; behavior может бросить, как упавший stdout/Sentry. */
function recordingSink(?Closure $behavior = null): object
{
    return new class($behavior) implements TransitionSink
    {
        /** @var list<ProcessEventEnvelope> */
        public array $received = [];

        public int $calls = 0;

        public function __construct(private readonly ?Closure $behavior) {}

        public function publish(ProcessEventEnvelope $envelope): void
        {
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('post-commit sink ran at transaction level '.DB::transactionLevel());
            }
            $this->calls++;
            if ($this->behavior !== null) {
                ($this->behavior)($envelope);
            }
            $this->received[] = $envelope;
        }
    };
}

/**
 * Публикация ждёт внешний commit. RefreshDatabase держит его открытым, поэтому наблюдение sink
 * коммитит этот уровень: callback исполняется уже при transactionLevel() === 0.
 */
function commitObservedDelivery(DeliveryEnvelope $delivery): DeliveryOutcome
{
    $outcome = app(WorkRunner::class)->runDelivery($delivery);
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    return $outcome;
}

function utcSecond(mixed $value): string
{
    return (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function stageFailedItem(): StageFailedItem
{
    return new StageFailedItem(ItemFailureReason::ContractRejected, new ArtifactRef('fixture', 'evidence/reject', LedgerFixtures::hash('reject')));
}

/** Listing handler и admitted work; PostCommitSinks подменяется ДО первого resolve WorkRunner. */
function observedRun(array $sinks, ?Closure $behavior = null): array
{
    app()->instance(PostCommitSinks::class, new PostCommitSinks($sinks));
    FinalizeFixtures::authority([StageCode::Listing]);
    $handler = new class implements StageHandler
    {
        public int $calls = 0;

        public ?Closure $behavior = null;

        /** @var array<string, mixed> */
        public array $seenContext = [];

        public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
        {
            $this->calls++;
            $this->seenContext = Context::all();

            return $this->behavior !== null ? ($this->behavior)($input, $execution) : FinalizeFixtures::succeeded(StageCode::Listing, 'observed');
        }
    };
    $handler->behavior = $behavior;
    app(StageRegistry::class)->bindHandler(StageCode::Listing, $handler);
    $work = ExecutionFixtures::listingWork();
    app(WorkFeeder::class)->tick('acquisition');
    $envelope = DeliveryEnvelope::fromScalars($work->value->value, ExecutionFixtures::delivery($work)->value, LedgerFixtures::uuid()->value, 1);

    return [$handler, $work, $envelope];
}

beforeEach(function (): void {
    // RefreshDatabase substitutes a manager that runs callbacks at level 1; these tests exercise the real outer commit.
    $connection = DB::connection();
    $manager = new DatabaseTransactionsManager;
    $manager->begin($connection->getName(), $connection->transactionLevel());
    $connection->setTransactionManager($manager);
});

afterEach(function (): void {
    if (DB::transactionLevel() !== 0) {
        return;
    }
    DB::statement('TRUNCATE TABLE prc_revisions, prc_status_refreshes, prc_network_permit_scopes, prc_network_permits, prc_network_demands, prc_outbound_operation_controls, prc_outbound_operations, prc_process_projection_receipts, prc_process_metric_totals, prc_process_projection_states, prc_observation_applications, prc_observations, prc_request_attempts, prc_process_reservations, prc_admission_groups, prc_process_attempts, prc_process_run_work, prc_process_transitions, prc_process_outbox, prc_process_work, prc_operation_intents, prc_crawl_blocks, prc_crawl_runs, prc_process_handler_holds, prc_process_incidents, prc_process_runs, prc_process_daemon_heartbeats, prc_process_lanes, prc_control_scopes RESTART IDENTITY');
});

test('sink failure после commit не повторяет handler: work succeeded, один transition, следующие sinks вызваны, delivery повтор — Rejected', function (): void {
    $broken = recordingSink(static fn () => throw new RuntimeException('stdout down https://u:pw-SINKSECRET@h.example/x?token=SINKTOKEN'));
    $after = recordingSink();
    Log::spy();
    [$handler, $work, $delivery] = observedRun([$broken, $after]);

    expect(commitObservedDelivery($delivery))->toBe(DeliveryOutcome::Executed)
        ->and($handler->calls)->toBe(1)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('state'))->toBe('succeeded')
        ->and(DB::table('prc_process_transitions')->where('work_id', $work->value->value)->where('new_state', 'succeeded')->count())->toBe(1)
        ->and($broken->calls)->toBe(1)
        ->and($after->received)->toHaveCount(1);

    // Повторная delivery того же generation: handler не вызывается, sink не получает второго события.
    expect(app(WorkRunner::class)->runDelivery($delivery))->toBe(DeliveryOutcome::Rejected)
        ->and($handler->calls)->toBe(1)
        ->and($after->calls)->toBe(1);

    // Сбой sink виден, но без URL/secret из текста исключения.
    Log::shouldHaveReceived('log')->withArgs(static fn (string $level, string $message, array $context): bool => $message === 'process.sink_failed'
        && $context['exception'] === RuntimeException::class
        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'SINKSECRET')
        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'SINKTOKEN'));
});

test('envelope typed: event/stage/attempt/run из БД, platform, trace; Context attempt изолирован и восстановлен в finally', function (): void {
    $sink = recordingSink();
    Context::add('trace_id', 'http-trace');
    Context::add('url', 'https://app.example/req');
    [$handler, $work, $delivery] = observedRun([$sink]);
    $run = DB::table('prc_process_runs')->value('id');
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['origin_run_id' => $run]);

    expect(commitObservedDelivery($delivery))->toBe(DeliveryOutcome::Executed);
    $attempt = DB::table('prc_process_attempts')->where('work_id', $work->value->value)->sole();
    $envelope = $sink->received[0];

    expect($envelope->event)->toBe(EventCode::WorkSucceeded)
        ->and($envelope->stage)->toBe(StageCode::Listing)
        ->and($envelope->workId->value->value)->toBe($work->value->value)
        ->and($envelope->attemptId->value)->toBe($attempt->id)
        ->and($envelope->runId?->value)->toBe($run)
        ->and($envelope->platformCode)->toBe('manual')
        ->and($envelope->traceId)->toMatch('/^[0-9a-f]{32}$/')
        ->and($envelope->itemFailureConsumed)->toBeFalse()
        ->and($envelope->toArray())->toMatchArray(['schema_version' => 1, 'event' => 'work.succeeded', 'stage' => 'listing', 'platform_code' => 'manual']);

    // Во время handler Context нёс IDs attempt (из БД), после delivery — только прежние значения.
    expect($handler->seenContext)->toMatchArray(['work_id' => $work->value->value, 'attempt_id' => $attempt->id, 'run_id' => $run, 'parent_trace_id' => 'http-trace'])
        ->and(Context::get('trace_id'))->toBe('http-trace')
        ->and(Context::get('url'))->toBe('https://app.example/req')
        ->and(Context::has('work_id'))->toBeFalse()
        ->and(Context::has('attempt_id'))->toBeFalse()
        ->and(Context::has('run_id'))->toBeFalse()
        ->and(Context::has('parent_trace_id'))->toBeFalse();
});

test('deferred envelope несёт bounded reason и retry_at; itemFailureConsumed только для item budget', function (): void {
    $sink = recordingSink();
    [, $work, $delivery] = observedRun([$sink], static fn (): StageDeferred => new StageDeferred(DeferralReason::NoRoute, new RetryAt(new DateTimeImmutable('+10 minutes'))));

    expect(app(WorkRunner::class)->runDelivery($delivery))->toBe(DeliveryOutcome::Executed);
    $row = DB::table('prc_process_work')->where('id', $work->value->value)->first();
    expect($sink->calls)->toBe(0);
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }
    $envelope = $sink->received[0];

    expect($envelope->event)->toBe(EventCode::WorkDeferred)
        ->and($envelope->reason)->toBe('no_route')
        ->and($envelope->reason)->toBe($row->reason_code)
        ->and($envelope->retryAt)->not->toBeNull()
        ->and($envelope->toArray()['retry_at'])->toBe(utcSecond($row->due_at))
        ->and($envelope->itemFailureConsumed)->toBeFalse()
        ->and((int) $row->item_failures)->toBe(0)
        ->and($envelope->toArray()['retry_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

test('rollback внешней транзакции не публикует уже успешный finalize', function (): void {
    $sink = recordingSink();
    [, $work, $delivery] = observedRun([$sink]);
    expect(DB::transactionLevel())->toBeGreaterThan(0);

    DB::beginTransaction();
    expect(app(WorkRunner::class)->runDelivery($delivery))->toBe(DeliveryOutcome::Executed)
        ->and($sink->calls)->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('state'))->toBe('succeeded');
    DB::rollBack();

    expect($sink->calls)->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('state'))->not->toBe('succeeded');
});

test('первые два StageFailedItem публикуют deferred(item_retry) и расход budget', function (): void {
    $sink = recordingSink();
    $failed = stageFailedItem();
    [, $firstWork, $firstDelivery] = observedRun([$sink], static fn (): StageOutcome => $failed);

    expect(app(WorkRunner::class)->runDelivery($firstDelivery))->toBe(DeliveryOutcome::Executed);
    $first = DB::table('prc_process_work')->where('id', $firstWork->value->value)->first();
    expect($sink->calls)->toBe(0)
        ->and((int) $first->item_failures)->toBe(1)
        ->and($first->state)->toBe('deferred')
        ->and($first->reason_code)->toBe(DeferralReason::ItemRetry->value);

    DB::update("UPDATE prc_process_work SET due_at = clock_timestamp() - interval '1 second' WHERE id = ? AND state = 'deferred'", [$firstWork->value->value]);
    app(WorkFeeder::class)->tick('acquisition');
    $secondDelivery = DeliveryEnvelope::fromScalars($firstWork->value->value, ExecutionFixtures::delivery($firstWork)->value, LedgerFixtures::uuid()->value, 1);
    expect(app(WorkRunner::class)->runDelivery($secondDelivery))->toBe(DeliveryOutcome::Executed);
    $second = DB::table('prc_process_work')->where('id', $firstWork->value->value)->first();
    expect((int) $second->item_failures)->toBe(2)
        ->and($second->state)->toBe('deferred')
        ->and($second->reason_code)->toBe(DeferralReason::ItemRetry->value);

    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    expect($sink->received)->toHaveCount(2);
    expect($sink->received[0]->event)->toBe(EventCode::WorkDeferred)
        ->and($sink->received[0]->itemFailureConsumed)->toBeTrue()
        ->and($sink->received[0]->reason)->toBe($first->reason_code)
        ->and($sink->received[0]->toArray()['retry_at'])->toBe(utcSecond($first->due_at));
    expect($sink->received[1]->event)->toBe(EventCode::WorkDeferred)
        ->and($sink->received[1]->itemFailureConsumed)->toBeTrue()
        ->and($sink->received[1]->reason)->toBe($second->reason_code)
        ->and($sink->received[1]->toArray()['retry_at'])->toBe(utcSecond($second->due_at));
});

test('StageDeferred(ItemRetry) не расходует item budget', function (): void {
    $sink = recordingSink();
    [, $work, $delivery] = observedRun([$sink], static fn (): StageDeferred => new StageDeferred(DeferralReason::ItemRetry, new RetryAt(new DateTimeImmutable('+15 minutes'))));
    $before = (int) DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures');

    expect(app(WorkRunner::class)->runDelivery($delivery))->toBe(DeliveryOutcome::Executed);
    $row = DB::table('prc_process_work')->where('id', $work->value->value)->first();
    expect($sink->calls)->toBe(0)
        ->and((int) $row->item_failures)->toBe($before)
        ->and($row->reason_code)->toBe(DeferralReason::ItemRetry->value);
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    $envelope = $sink->received[0];
    expect($envelope->event)->toBe(EventCode::WorkDeferred)
        ->and($envelope->itemFailureConsumed)->toBeFalse()
        ->and($envelope->reason)->toBe($row->reason_code)
        ->and($envelope->toArray()['retry_at'])->toBe(utcSecond($row->due_at));
});

test('journal INSERT failure откатывает result: work остаётся running, domain proof не записан, sinks не получили событие', function (): void {
    $sink = recordingSink();
    [$handler, $work, $delivery] = observedRun([$sink]);
    DB::unprepared("CREATE FUNCTION fixture_journal_reject() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.entity_kind = 'work' AND NEW.new_state = 'succeeded' THEN RAISE EXCEPTION 'fixture journal failure'; END IF; RETURN NEW; END \$\$");
    DB::unprepared('CREATE TRIGGER fixture_journal_reject BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION fixture_journal_reject()');
    try {
        expect(fn () => app(WorkRunner::class)->runDelivery($delivery))->toThrow(QueryException::class, 'fixture journal failure');
        expect($handler->calls)->toBe(1)
            ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('state'))->toBe('running')
            ->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(0)
            ->and(DB::table('prc_process_transitions')->where('work_id', $work->value->value)->where('new_state', 'succeeded')->count())->toBe(0)
            ->and($sink->calls)->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER fixture_journal_reject ON prc_process_transitions');
        DB::unprepared('DROP FUNCTION fixture_journal_reject()');
    }
});

test('envelope отвергает свободный текст в reason и не-hex trace_id; toArray опускает пустые поля', function (): void {
    $make = static fn (?string $reason, ?string $trace): ProcessEventEnvelope => new ProcessEventEnvelope(
        eventId: LedgerFixtures::uuid(), event: EventCode::WorkBlocked, runId: null, workId: new WorkId(LedgerFixtures::uuid()),
        attemptId: LedgerFixtures::uuid(), stage: StageCode::Download, platformCode: null, reason: $reason, retryAt: null,
        itemFailureConsumed: false, traceId: $trace, occurredAt: new DateTimeImmutable,
    );

    expect(fn () => $make('fetch failed https://h.example/x?token=1', null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $make(null, 'not-a-trace'))->toThrow(InvalidArgumentException::class)
        ->and(array_keys($make('provider_result_unknown', null)->toArray()))->not->toContain('run_id', 'platform_code', 'trace_id', 'retry_at');
});

test('correlation берёт run/platform из БД (не из Redis) и платформу из cohort_key', function (): void {
    $work = ExecutionFixtures::listingWork();
    $run = DB::table('prc_process_runs')->value('id');
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['origin_run_id' => $run, 'cohort_key' => 'platform:eis']);
    $lease = new ExecutionLease(
        $work, LedgerFixtures::uuid(), 1, LedgerFixtures::uuid(), new DateTimeImmutable('+1 minute'), 1,
        LedgerFixtures::hash('input'), 0, null, StageCode::Listing, 'fixture-listing/1',
    );

    $log = app(ExecutionCorrelation::class)->describe($lease);

    expect($log->runId?->value)->toBe($run)
        ->and($log->platformCode)->toBe('eis')
        ->and($log->toContext())->toMatchArray(['run_id' => $run, 'work_id' => $work->value->value, 'stage' => 'listing', 'platform_code' => 'eis'])
        ->and($log->traceId)->toMatch('/^[0-9a-f]{32}$/');
});
