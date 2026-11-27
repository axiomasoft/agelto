<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Execution\QueueRoute;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\Daemons\RecoveryReconciler;
use App\Services\Procurement\Process\Daemons\RelayDaemon;
use App\Services\Procurement\Process\Execution\StageQueueMap;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;

pest()->group('daemon-reconciliation', 'delivery-and-daemon-lifecycle', 'claim-recovery-and-invariants');

// acceptance полной state machine: ready → admitted → outbox → relay → настоящий Redis →
// сериализованный ExecuteWorkJob → claim → handler → finalize. Каждый daemon instance — отдельный
// PHP процесс со своей PostgreSQL connection; два relay и два reconcile стартуют одновременно без
// подсказок порядка. Redis теряет всё посреди работы; worker погибает после claim. Ledger и queue
// listing очищаются до и после (только isolated agelto_test и Redis rig).
beforeEach(function (): void {
    ExecutionFixtures::truncateLedger();
    acceptanceClearQueue();
});
afterEach(function (): void {
    ExecutionFixtures::truncateLedger();
    acceptanceClearQueue();
});

function acceptanceRoute(): QueueRoute
{
    return app(StageQueueMap::class)->forStage(StageCode::Listing);
}

function acceptanceQueueSize(): int
{
    $route = acceptanceRoute();

    return (int) Queue::connection($route->connection)->size($route->queue);
}

/** @return array{pending: int, reserved: int, delayed: int} */
function acceptanceQueueCounts(): array
{
    $route = acceptanceRoute();
    $connection = Queue::connection($route->connection);

    return [
        'pending' => (int) $connection->pendingSize($route->queue),
        'reserved' => (int) $connection->reservedSize($route->queue),
        'delayed' => (int) $connection->delayedSize($route->queue),
    ];
}

function acceptanceClearQueue(): void
{
    $route = acceptanceRoute();
    $connection = Queue::connection($route->connection);
    if ($connection instanceof ClearableQueue) {
        $connection->clear($route->queue);
    }
}

/** Queue worker резервирует следующую delivery listing queue (как Horizon worker до fire). */
function acceptancePop(): Job
{
    $route = acceptanceRoute();

    return Queue::connection($route->connection)->pop($route->queue) ?? throw new LogicException('Listing queue is empty.');
}

/** Настоящий payload delivery из Redis: только scalar envelope ExecuteWorkJob. */
function acceptanceCommand(Job $job): ExecuteWorkJob
{
    /** @var array{data: array{command: string}} $payload */
    $payload = json_decode($job->getRawBody(), true, flags: JSON_THROW_ON_ERROR);
    $command = unserialize($payload['data']['command']);

    return $command instanceof ExecuteWorkJob ? $command : throw new LogicException('Not a Process delivery.');
}

/** Fixture listing handler этого процесса: записывает исполненные work. */
function acceptanceHandler(): object
{
    $handler = new class implements StageHandler
    {
        /** @var list<string> */
        public array $executed = [];

        public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
        {
            $work = $execution->lease()->workId->value->value;
            $this->executed[] = $work;

            return FinalizeFixtures::succeeded(StageCode::Listing, 'p112-'.$work);
        }
    };
    app(StageRegistry::class)->bindHandler(StageCode::Listing, $handler);

    return $handler;
}

/** Instance relay daemon: один tick настоящего RelayDaemon в собственном процессе. */
function acceptanceRelay(): string
{
    return '$tick = app(\App\Services\Procurement\Process\Daemons\RelayDaemon::class)->tick();'
        .'return ["processed" => $tick->processed, "error" => $tick->errorCode];';
}

/** Instance reconcile daemon с категориями настоящего RecoveryReconciler, один tick. */
function acceptanceReconcile(): string
{
    return '\Tests\Support\ProcessCore\FinalizeFixtures::bind(\App\Enums\Procurement\Process\Core\StageCode::Listing);'
        .'$daemon = app(\App\Services\Procurement\Process\Daemons\ReconcileDaemon::class);'
        .'foreach (app(\App\Services\Procurement\Process\Daemons\RecoveryReconciler::class)->categories() as $category) { $daemon->register($category); }'
        .'$tick = $daemon->tick();'
        .'return ["processed" => $tick->processed, "error" => $tick->errorCode];';
}

/**
 * Relay instance погибает после своей claim tx: до publish либо после publish до mark. Повторяет
 * шаги RelayDaemon настоящими repository/codec/publisher; ни markPublished, ни releaseClaim нет.
 */
function acceptanceKilledRelay(bool $published): string
{
    return str_replace('__PUBLISHED__', $published ? 'true' : 'false', <<<'PHP'
        $messages = app(\App\Services\Procurement\Process\Infrastructure\ProcessTransaction::class)->run(
            fn (): array => app(\App\Repositories\Procurement\Process\Work\OutboxRepository::class)->claim(
                \App\Enums\Procurement\Process\Core\OutboxKind::WorkWake,
                new \App\Dto\Procurement\Process\Primitives\ProcessUuid(strtolower((string) \Illuminate\Support\Str::uuid())),
                1,
                30,
            ),
        );
        foreach ($messages as $message) {
            if (__PUBLISHED__) {
                app(\App\Services\Procurement\Process\Contracts\WorkWakePublisher::class)->publish(
                    app(\App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec::class)->decodeWorkWake($message->payload),
                );
            }
        }

        return ['ids' => array_map(static fn ($message): string => $message->messageId->value, $messages)];
        PHP);
}

/**
 * Queue worker исполняет payload из Redis и погибает внутри handler после claim: процесс
 * завершается без finalize и без finally, claimed lease остаётся durable running.
 */
function acceptanceCrashingWorker(Job $job): string
{
    return str_replace('__BODY__', base64_encode($job->getRawBody()), <<<'PHP'
        \Tests\Support\ProcessCore\FinalizeFixtures::bind(\App\Enums\Procurement\Process\Core\StageCode::Listing);
        app(\App\Services\Procurement\Process\Execution\StageRegistry::class)->bindHandler(
            \App\Enums\Procurement\Process\Core\StageCode::Listing,
            new class implements \App\Services\Procurement\Process\Contracts\StageHandler
            {
                public function execute(\App\Dto\Procurement\Process\Inputs\WorkInput $input, \App\Dto\Procurement\Process\Execution\ExecutionContext $execution): \App\Dto\Procurement\Process\Outcomes\StageOutcome
                {
                    fwrite(STDOUT, json_encode(['crashed' => $execution->lease()->workId->value->value])."\n");

                    exit(0);
                }
            },
        );
        $payload = json_decode(base64_decode('__BODY__'), true, flags: JSON_THROW_ON_ERROR);
        app()->call([unserialize($payload['data']['command']), 'handle']);

        return ['crashed' => null];
        PHP);
}

test('два relay и два reconcile daemon, Redis FLUSHALL посреди работы и гибель worker после claim: каждая work исполнена и принята ровно один раз, старое поколение не публикует', function (): void {
    // FLUSHALL разрешён только на изолированном rig Redis, никогда на dev/shared.
    expect(getenv('PROCESS_CORE_TEST'))->toBe('1')
        ->and(config('database.redis.default.host'))->toBe('core-redis');
    $results = FinalizeFixtures::authority([StageCode::Listing], lanes: [
        'acquisition' => ['max_outstanding' => 20, 'max_running' => 10, 'low_watermark' => 19, 'refill_max' => 10],
        'acquisition.listing' => ['max_outstanding' => 20, 'max_running' => 10, 'low_watermark' => 19, 'refill_max' => 10],
    ])['listing'];
    $handler = acceptanceHandler();
    $works = [];
    foreach (range(1, 4) as $index) {
        $work = ExecutionFixtures::listingWork();
        $works[$work->value->value] = $work;
    }

    // Два relay instance одновременно: каждое wake опубликовано ровно одним из них, ровно один раз.
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(4);
    $relayed = ConcurrentTransactions::concurrently(['relay-a' => acceptanceRelay(), 'relay-b' => acceptanceRelay()]);
    expect([$relayed['relay-a']['error'], $relayed['relay-b']['error']])->toBe([null, null])
        ->and($relayed['relay-a']['processed'] + $relayed['relay-b']['processed'])->toBe(4)
        ->and(acceptanceQueueSize())->toBe(4)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->whereNull('published_at')->count())->toBe(0)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->where('attempts', '<>', 1)->count())->toBe(0);

    // Worker A зарезервировал delivery, но не успел исполнить её до потери Redis.
    $reserved = acceptancePop();
    // Worker B исполнил свою delivery до потери Redis.
    acceptancePop()->fire();
    // Worker C погиб внутри handler после claim.
    $crashedJob = acceptancePop();
    $crashed = acceptanceCommand($crashedJob)->workId;
    expect(ConcurrentTransactions::execute(acceptanceCrashingWorker($crashedJob)))->toBe(['crashed' => $crashed])
        ->and(ExecutionFixtures::work($works[$crashed])->state)->toBe('running')
        ->and(acceptanceQueueSize())->toBe(3)
        ->and(acceptanceQueueCounts())->toBe(['pending' => 1, 'reserved' => 2, 'delayed' => 0]);

    Redis::connection((string) config('queue.connections.'.acceptanceRoute()->connection.'.connection', 'default'))->flushall();
    expect(acceptanceQueueSize())->toBe(0);

    // Простой: delivery deadline admitted истёк. Lease погибшего worker внутри 2 s margin recovery
    // не трогает; истёкший на 3 s восстанавливается за один tick (bound lease + margin + tick).
    DB::update("UPDATE prc_process_work SET dispatch_claim_until = clock_timestamp() - interval '1 second' WHERE state = 'admitted'");
    FinalizeFixtures::expire($works[$crashed], 1);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(0);
    FinalizeFixtures::expire($works[$crashed], 3);

    $ticks = ConcurrentTransactions::concurrently(['reconcile-a' => acceptanceReconcile(), 'reconcile-b' => acceptanceReconcile()]);
    expect([$ticks['reconcile-a']['error'], $ticks['reconcile-b']['error']])->toBe([null, null])
        // Две admitted redelivery и один expired running — каждая work ровно одним instance.
        ->and($ticks['reconcile-a']['processed'] + $ticks['reconcile-b']['processed'])->toBe(3)
        ->and(ExecutionFixtures::work($works[$crashed])->state)->toBe('ready')
        ->and(DB::table('prc_process_work')->where('state', 'admitted')->count())->toBe(2);

    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(1);
    $relayed = ConcurrentTransactions::concurrently(['relay-a' => acceptanceRelay(), 'relay-b' => acceptanceRelay()]);
    expect($relayed['relay-a']['processed'] + $relayed['relay-b']['processed'])->toBe(3)
        ->and(acceptanceQueueSize())->toBe(3);
    foreach (range(1, 3) as $delivery) {
        acceptancePop()->fire();
    }
    // Delivery, зарезервированная до потери Redis, приходит последней: старое поколение отвергнуто.
    $stale = acceptanceCommand($reserved);
    $reserved->fire();

    $executed = $handler->executed;
    sort($executed);
    $ids = array_keys($works);
    sort($ids);
    expect($executed)->toBe($ids)
        ->and($stale->dispatchGeneration)->toBe(1)
        ->and(ExecutionFixtures::work($works[$stale->workId])->dispatch_generation)->toBe(2)
        ->and($results->resultWrites)->toBe(4)
        ->and(DB::table('prc_process_work')->where('state', 'succeeded')->count())->toBe(4)
        ->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(4)
        // Одна accepted финализация на work.
        ->and(DB::table('prc_process_transitions')->where('new_state', 'succeeded')->count())->toBe(4)
        ->and(DB::table('prc_process_attempts')->count())->toBe(5)
        ->and(DB::table('prc_process_attempts')->where('work_id', $crashed)->orderBy('generation')->pluck('terminal_kind')->all())->toBe(['interrupted', 'succeeded'])
        ->and(ExecutionFixtures::work($works[$crashed])->generation)->toBe(3)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and(DB::table('prc_admission_groups')->where('state', '<>', 'released')->count())->toBe(0)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->count())->toBe(7)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->whereNull('published_at')->count())->toBe(0)
        ->and(DB::table('prc_process_incidents')->count())->toBe(0)
        ->and(acceptanceQueueSize())->toBe(0);
});

test('relay погибает после claim до publish и после publish до mark: другой instance публикует после claim_until, duplicate delivery исполняется один раз', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $handler = acceptanceHandler();
    $works = [ExecutionFixtures::listingWork(), ExecutionFixtures::listingWork()];
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(2);

    $beforePublish = ConcurrentTransactions::execute(acceptanceKilledRelay(published: false));
    $afterPublish = ConcurrentTransactions::execute(acceptanceKilledRelay(published: true));
    expect($beforePublish['ids'])->toHaveCount(1)
        ->and($afterPublish['ids'])->toHaveCount(1)
        ->and(array_intersect($beforePublish['ids'], $afterPublish['ids']))->toBe([])
        ->and(acceptanceQueueSize())->toBe(1)
        // Живые claims погибших instances не отнимаются раньше claim_until.
        ->and(app(RelayDaemon::class)->tick()->processed)->toBe(0)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->whereNull('published_at')->count())->toBe(2);

    DB::update("UPDATE prc_process_outbox SET claim_until = clock_timestamp() - interval '1 second' WHERE kind = 'work_wake' AND published_at IS NULL");
    $relayed = ConcurrentTransactions::concurrently(['relay-a' => acceptanceRelay(), 'relay-b' => acceptanceRelay()]);
    expect($relayed['relay-a']['processed'] + $relayed['relay-b']['processed'])->toBe(2)
        // Wake, опубликованное погибшим relay до mark, опубликовано повторно: duplicate в Redis.
        ->and(acceptanceQueueSize())->toBe(3);
    foreach (range(1, 3) as $delivery) {
        acceptancePop()->fire();
    }

    expect($handler->executed)->toHaveCount(2)
        ->and(array_unique($handler->executed))->toHaveCount(2)
        ->and($results->resultWrites)->toBe(2)
        ->and(array_map(fn (WorkId $work): string => ExecutionFixtures::work($work)->state, $works))->toBe(['succeeded', 'succeeded'])
        ->and(DB::table('prc_process_attempts')->count())->toBe(2)
        ->and(DB::table('prc_process_transitions')->where('new_state', 'succeeded')->count())->toBe(2)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->whereNull('published_at')->count())->toBe(0)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->pluck('attempts')->all())->toBe([2, 2])
        ->and(acceptanceQueueSize())->toBe(0);
});
