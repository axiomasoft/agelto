<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\RetryHandlerFaultedWork;
use App\Dto\Procurement\Process\Execution\DeliveryEnvelope;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\DeliveryOutcome;
use App\Enums\Procurement\Process\Outcomes\ProviderRecoveryMode;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\Daemons\RecoveryReconciler;
use App\Services\Procurement\Process\Daemons\RelayDaemon;
use App\Services\Procurement\Process\Execution\StageQueueMap;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Process\Execution\WorkRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('daemon-reconciliation', 'delivery-and-daemon-lifecycle', 'claim-recovery-and-invariants');

// Отдельные PHP процессы и PostgreSQL connections коммитят rows; Redis — изолированный rig
// (PROCESS_CORE_TEST=1, core-redis). Ledger очищается целиком до и после теста.
beforeEach(fn () => ExecutionFixtures::truncateLedger());
afterEach(fn () => ExecutionFixtures::truncateLedger());

/** Тело worker: ReconcileDaemon с категориями настоящего RecoveryReconciler, один tick. */
function reconcileWorker(): string
{
    return '\Tests\Support\ProcessCore\FinalizeFixtures::bind(\App\Enums\Procurement\Process\Core\StageCode::Listing);'
        .'$daemon = app(\App\Services\Procurement\Process\Daemons\ReconcileDaemon::class);'
        .'foreach (app(\App\Services\Procurement\Process\Daemons\RecoveryReconciler::class)->categories() as $category) { $daemon->register($category); }'
        .'$tick = $daemon->tick();'
        .'return ["processed" => $tick->processed, "error" => $tick->errorCode, "categories" => $daemon->categories()];';
}

test('два reconcile daemon после потери Redis: потерянная admitted delivery и expired running восстановлены ровно один раз, одна accepted финализация', function (): void {
    // FLUSHALL разрешён только на изолированном rig Redis, никогда на dev/shared.
    expect(getenv('PROCESS_CORE_TEST'))->toBe('1')
        ->and(config('database.redis.default.host'))->toBe('core-redis');

    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $handler = new class implements StageHandler
    {
        public int $calls = 0;

        public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
        {
            $this->calls++;

            return FinalizeFixtures::succeeded(StageCode::Listing, 'redelivered');
        }
    };
    app(StageRegistry::class)->bindHandler(StageCode::Listing, $handler);
    $route = app(StageQueueMap::class)->forStage(StageCode::Listing);
    $queueSize = static fn (): int => (int) Queue::connection($route->connection)->size($route->queue);
    $lost = ExecutionFixtures::listingWork();
    $crashed = ExecutionFixtures::listingWork();
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(2);
    $crashLease = app(WorkStoreRepository::class)->claim($crashed, ExecutionFixtures::delivery($crashed))->lease;
    expect($crashLease)->not->toBeNull();
    $staleDelivery = ExecutionFixtures::delivery($lost);

    // Relay публикует wake в настоящий Redis, затем Redis теряет всё (published_at старых сообщений не authority).
    expect(app(RelayDaemon::class)->tick()->processed)->toBe(2)
        ->and($queueSize())->toBe(2);
    Redis::connection((string) config('queue.connections.'.$route->connection.'.connection', 'default'))->flushall();
    expect($queueSize())->toBe(0);

    // Простой больше 5 минут: delivery deadline admitted и lease running давно истекли.
    DB::update("UPDATE prc_process_work SET dispatch_claim_until = clock_timestamp() - interval '10 minutes' WHERE id = ?", [$lost->value->value]);
    FinalizeFixtures::expire($crashed, 600);

    $ticks = ConcurrentTransactions::concurrently(['one' => reconcileWorker(), 'two' => reconcileWorker()]);

    expect($ticks['one']['error'])->toBeNull()
        ->and($ticks['two']['error'])->toBeNull()
        ->and($ticks['one']['categories'])->toBe(['document_storage_cleanup', 'outbound_expired_owners', 'outbound_probes', 'expired_running', 'admitted', 'blocked_release', 'blocked_provider', 'handler_fault_sweep', 'invariant'])
        // Каждая work восстановлена ровно одним из двух daemon: SKIP LOCKED + повтор locked predicates.
        ->and($ticks['one']['processed'] + $ticks['two']['processed'])->toBe(2);

    $lostRow = ExecutionFixtures::work($lost);
    $crashedRow = ExecutionFixtures::work($crashed);
    expect([$lostRow->state, $lostRow->dispatch_generation])->toBe(['admitted', $staleDelivery->value + 1])
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'wake:'.$lost->value->value.':'.($staleDelivery->value + 1))->count())->toBe(1)
        ->and(DB::table('prc_admission_groups')->where('work_id', $lost->value->value)->where('state', '<>', 'released')->count())->toBe(1)
        ->and([$crashedRow->state, $crashedRow->generation])->toBe(['ready', 2])
        ->and(DB::table('prc_process_attempts')->where('work_id', $crashed->value->value)->where('terminal_kind', 'interrupted')->count())->toBe(1)
        ->and(DB::table('prc_process_transitions')->where('work_id', $crashed->value->value)->where('new_state', 'ready')->where('entity_seq', '>', 3)->count())->toBe(1)
        ->and(DB::table('prc_process_reservations')->where('work_id', $crashed->value->value)->count())->toBe(0);

    // Redelivery: relay кладёт новый wake в чистый Redis; старая delivery stale, новая исполняется один раз.
    expect(app(RelayDaemon::class)->tick()->processed)->toBe(1)
        ->and($queueSize())->toBe(1);
    $runner = app(WorkRunner::class);
    $envelope = static fn (int $generation): DeliveryEnvelope => DeliveryEnvelope::fromScalars($lost->value->value, $generation, LedgerFixtures::uuid()->value, 1);

    expect($runner->runDelivery($envelope($staleDelivery->value)))->toBe(DeliveryOutcome::Rejected)
        ->and($handler->calls)->toBe(0)
        ->and($runner->runDelivery($envelope($staleDelivery->value + 1)))->toBe(DeliveryOutcome::Executed)
        ->and($handler->calls)->toBe(1)
        // Duplicate delivery того же поколения: work terminal, handler и publication не повторяются.
        ->and($runner->runDelivery($envelope($staleDelivery->value + 1)))->toBe(DeliveryOutcome::Rejected)
        ->and($handler->calls)->toBe(1)
        ->and($results->resultWrites)->toBe(1)
        ->and(ExecutionFixtures::work($lost)->state)->toBe('succeeded');
});

test('manual retry: concurrent command с одним expected seq создаёт один active intent; повтор победившей команды дедуплицируется', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $finalizer = app(WorkFinalizer::class);
    $finalizer->finishHandlerFault(FinalizeFixtures::running($work), FinalizeFixtures::fault('one'));
    $finalizer->finishHandlerFault(FinalizeFixtures::rerun($work), FinalizeFixtures::fault('two'));
    $finalizer->finishHandlerFault(FinalizeFixtures::rerun($work), FinalizeFixtures::fault('three'));
    $seq = ExecutionFixtures::work($work)->transition_seq;
    $commands = [LedgerFixtures::uuid(), LedgerFixtures::uuid()];
    $literal = "new \\App\Dto\Procurement\Process\Primitives\WorkId(new \\App\Dto\Procurement\Process\Primitives\ProcessUuid('".$work->value->value."'))";
    $operation = static fn (string $command): string => '$result = app(\\App\\Actions\\Procurement\\Process\\RetryHandlerFaultedWork::class)->execute('.$literal.', '.$seq.", new \\App\Dto\Procurement\Process\Primitives\ProcessUuid('".$command."'), 'fixed handler'); return ['status' => \$result->status->value, 'successor' => \$result->successor?->value->value];";
    $binding = '\\Tests\\Support\\ProcessCore\\FinalizeFixtures::bind(\\App\Enums\Procurement\Process\Core\StageCode::Listing);';
    $results = ConcurrentTransactions::concurrently([
        'winner' => $binding.'return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::holdUntilWaiter(function () { '.$operation($commands[0]->value).' });',
        'stale' => $binding.'return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::afterHolder(function () { '.$operation($commands[1]->value).' });',
    ]);
    expect($results['winner']['status'])->toBe('applied')
        ->and($results['stale'])->toBe(['status' => 'version_conflict', 'successor' => null])
        ->and(DB::table('prc_operation_intents')->where('state', 'active')->count())->toBe(1)
        ->and(DB::table('prc_process_work')->count())->toBe(2)
        ->and(ExecutionFixtures::work($work)->state)->toBe('superseded');
    $replay = app(RetryHandlerFaultedWork::class)->execute($work, $seq, $commands[0], 'same command');
    expect([$replay->status->value, $replay->successor?->value->value])->toBe(['duplicate', $results['winner']['successor']]);
});

test('два periodic reconcile daemon принимают один поздний fixture receipt после committed blocked без новой queue delivery', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $resolver = FixtureProviderRecoveryResolver::install();
    $work = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($work);
    $unknown = FixtureProviderRecoveryResolver::plan(ProviderRecoveryMode::BlockedUnknown, FinalizeFixtures::inputHash($work));
    $resolver->finding($work, $unknown);
    FinalizeFixtures::expire($work);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $verified = FixtureProviderRecoveryResolver::resolved($unknown, ProviderRecoveryMode::ResumeFromReceipt);
    DB::update("UPDATE prc_process_work SET provider_recovery_due_at = clock_timestamp() - interval '1 second' WHERE id = ?", [$work->value->value]);
    $seq = ExecutionFixtures::work($work)->transition_seq;
    $wakes = DB::table('prc_process_outbox')->where('kind', 'work_wake')->count();
    $literal = "new \\App\Dto\Procurement\Process\Primitives\WorkId(new \\App\Dto\Procurement\Process\Primitives\ProcessUuid('".$work->value->value."'))";
    $binding = '\\Tests\\Support\\ProcessCore\\FinalizeFixtures::bind(\\App\Enums\Procurement\Process\Core\StageCode::Listing);'
        .'$resolver = \\Tests\\Support\\ProcessCore\\FixtureProviderRecoveryResolver::install();'
        .'$resolver->finding('.$literal.", unserialize(base64_decode('".base64_encode(serialize($verified))."')));";
    $operation = '$daemon = app(\\App\Services\Procurement\Process\Daemons\ReconcileDaemon::class);'
        .'foreach (app(\\App\Services\Procurement\Process\Daemons\RecoveryReconciler::class)->categories() as $category) { $daemon->register($category); }'
        .'$tick = $daemon->tick(); return ["processed" => $tick->processed, "error" => $tick->errorCode, "settlements" => count($resolver->settlements)];';
    $ticks = ConcurrentTransactions::concurrently([
        'winner' => $binding.'return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::holdUntilWaiter(function () use ($resolver) { '.$operation.' });',
        'other' => $binding.'return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::afterHolder(function () use ($resolver) { '.$operation.' });',
    ]);
    expect([$ticks['winner']['error'], $ticks['other']['error']])->toBe([null, null])
        ->and($ticks['winner']['processed'] + $ticks['other']['processed'])->toBe(1)
        ->and($ticks['winner']['settlements'] + $ticks['other']['settlements'])->toBe(1)
        ->and([ExecutionFixtures::work($work)->state, ExecutionFixtures::work($work)->transition_seq])->toBe(['ready', $seq + 1])
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->count())->toBe($wakes)
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->count())->toBe(1)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0);
})->group('analysis-execution', 'provider-and-worker-recovery');
