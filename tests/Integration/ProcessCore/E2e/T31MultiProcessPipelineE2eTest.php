<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\PlatformCode;
use App\Enums\Procurement\Process\StageCode;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\User;
use App\Repositories\Procurement\Process\PlatformMetricsReadRepository;
use App\Services\Procurement\Process\StageQueueMap;
use App\Services\Procurement\Process\StageRegistry;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;

use function Pest\Laravel\artisan;

pest()->group('p316', 'e2e', 't31');

beforeEach(function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DB::statement('TRUNCATE prc_procurements, prc_registries, prc_platforms, users, roles, model_has_roles CASCADE');
    DocumentStorageFixtures::reset();
    Redis::connection()->flushdb();
    artisan('guard:sync-roles')->assertSuccessful();
});

afterEach(function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DB::statement('TRUNCATE prc_procurements, prc_registries, prc_platforms, users, roles, model_has_roles CASCADE');
    DocumentStorageFixtures::reset();
    Redis::connection()->flushdb();
});

test('отдельные OS-процессы daemon feeder, relay, Redis worker, projector, reconcile и CLI работают согласованно на общей PostgreSQL и Redis', function (): void {
    expect(getenv('PROCESS_CORE_TEST'))->toBe('1')
        ->and(config('database.redis.default.host'))->toBe('core-redis');

    $platform = Platform::query()->firstOrCreate(
        ['code' => 'eis'],
        ['name' => 'ЕИС', 'base_url' => 'https://zakupki.gov.ru', 'is_active' => true, 'is_public' => true],
    );
    Registry::query()->firstOrCreate(
        ['platform_id' => $platform->id, 'code' => 'fz44'],
        ['name' => '44-ФЗ', 'settings' => []],
    );

    $stageRegistry = new StageRegistry;
    app()->instance(StageRegistry::class, $stageRegistry);
    FinalizeFixtures::authority([StageCode::Listing, StageCode::Detail]);
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'platform:eis');
    ExecutionFixtures::ensureWorkScopes(StageCode::Detail, 'platform:eis');

    $listingWork = ExecutionFixtures::listingWork('platform:eis');
    $detailWork = DB::transaction(static fn () => ExecutionFixtures::detailWork('platform:eis'));
    DB::table('prc_process_work')
        ->whereIn('id', [$listingWork->value->value, $detailWork->value->value])
        ->update(['platform_id' => $platform->id]);

    $parentPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;

    // 1. Run FeederDaemon and two concurrent RelayDaemon instances in independent OS processes.
    $feederResult = ConcurrentTransactions::execute(<<<'PHP'
        \Tests\Support\ProcessCore\FinalizeFixtures::bind(\App\Enums\Procurement\Process\StageCode::Listing);
        \Tests\Support\ProcessCore\FinalizeFixtures::bind(\App\Enums\Procurement\Process\StageCode::Detail);
        $tick = app(\App\Services\Procurement\Process\FeederDaemon::class)->tick();

        return [
            'pid' => (int) \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            'processed' => $tick->processed,
            'error' => $tick->errorCode,
        ];
        PHP);

    expect($feederResult['pid'])->not->toBe($parentPid)
        ->and($feederResult['error'])->toBeNull()
        ->and($feederResult['processed'])->toBeGreaterThanOrEqual(2);

    $relayCode = <<<'PHP'
        $tick = app(\App\Services\Procurement\Process\RelayDaemon::class)->tick();

        return [
            'pid' => (int) \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            'processed' => $tick->processed,
            'error' => $tick->errorCode,
        ];
        PHP;
    $relayed = ConcurrentTransactions::concurrently(['relay-a' => $relayCode, 'relay-b' => $relayCode]);

    expect([$relayed['relay-a']['error'], $relayed['relay-b']['error']])->toBe([null, null])
        ->and($relayed['relay-a']['pid'])->not->toBe($relayed['relay-b']['pid'])
        ->and($relayed['relay-a']['processed'] + $relayed['relay-b']['processed'])->toBe(2)
        ->and(ExecutionFixtures::work($listingWork)->state)->toBe('admitted')
        ->and(ExecutionFixtures::work($detailWork)->state)->toBe('admitted')
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->whereNotNull('published_at')->count())->toBe(2);

    // 2. Pop and execute jobs from real Redis queues inside an independent PHP worker OS process.
    $workerResult = ConcurrentTransactions::execute(<<<'PHP'
        \Tests\Support\ProcessCore\FinalizeFixtures::bind(\App\Enums\Procurement\Process\StageCode::Listing);
        \Tests\Support\ProcessCore\FinalizeFixtures::bind(\App\Enums\Procurement\Process\StageCode::Detail);
        foreach ([\App\Enums\Procurement\Process\StageCode::Listing, \App\Enums\Procurement\Process\StageCode::Detail] as $stage) {
            app(\App\Services\Procurement\Process\StageRegistry::class)->bindHandler(
                $stage,
                new class($stage) implements \App\Services\Procurement\Process\Contracts\StageHandler
                {
                    public function __construct(private \App\Enums\Procurement\Process\StageCode $stage) {}

                    public function execute(\App\Dto\Procurement\Process\WorkInput $input, \App\Dto\Procurement\Process\ExecutionContext $execution): \App\Dto\Procurement\Process\StageOutcome
                    {
                        return \Tests\Support\ProcessCore\FinalizeFixtures::succeeded($this->stage, 'e2e-mp-'.$execution->lease()->workId->value->value);
                    }
                },
            );
        }
        $fired = 0;
        $map = app(\App\Services\Procurement\Process\StageQueueMap::class);
        foreach ([\App\Enums\Procurement\Process\StageCode::Listing, \App\Enums\Procurement\Process\StageCode::Detail] as $stage) {
            $route = $map->forStage($stage);
            while (($job = \Illuminate\Support\Facades\Queue::connection($route->connection)->pop($route->queue)) !== null) {
                $job->fire();
                $fired++;
            }
        }

        return [
            'pid' => (int) \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            'fired' => $fired,
        ];
        PHP);

    expect($workerResult['pid'])->not->toBe($parentPid)
        ->and($workerResult['fired'])->toBe(2)
        ->and(ExecutionFixtures::work($listingWork)->state)->toBe('succeeded')
        ->and(ExecutionFixtures::work($detailWork)->state)->toBe('succeeded');

    // 3. Run two concurrent ProjectorDaemon instances and one ReconcileDaemon instance in independent OS processes.
    $projectorCode = <<<'PHP'
        $tick = app(\App\Services\Procurement\Process\ProjectorDaemon::class)->tick();

        return [
            'pid' => (int) \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            'processed' => $tick->processed,
            'error' => $tick->errorCode,
        ];
        PHP;
    $projected = ConcurrentTransactions::concurrently(['projector-a' => $projectorCode, 'projector-b' => $projectorCode]);

    expect([$projected['projector-a']['error'], $projected['projector-b']['error']])->toBe([null, null])
        ->and($projected['projector-a']['pid'])->not->toBe($projected['projector-b']['pid'])
        ->and($projected['projector-a']['processed'] + $projected['projector-b']['processed'])->toBeGreaterThanOrEqual(4)
        ->and(DB::table('prc_process_outbox')->where('kind', 'projection')->whereNull('published_at')->count())->toBe(0)
        ->and(DB::table('prc_process_metric_totals')->count())->toBeGreaterThan(0)
        ->and(DB::table('prc_process_projection_receipts')->count())->toBeGreaterThan(0);

    $reconcileResult = ConcurrentTransactions::execute(<<<'PHP'
        \Tests\Support\ProcessCore\FinalizeFixtures::bind(\App\Enums\Procurement\Process\StageCode::Listing);
        \Tests\Support\ProcessCore\FinalizeFixtures::bind(\App\Enums\Procurement\Process\StageCode::Detail);
        $daemon = app(\App\Services\Procurement\Process\ReconcileDaemon::class);
        foreach (app(\App\Services\Procurement\Process\RecoveryReconciler::class)->categories() as $category) {
            $daemon->register($category);
        }
        $tick = $daemon->tick();

        return [
            'pid' => (int) \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            'processed' => $tick->processed,
            'error' => $tick->errorCode,
        ];
        PHP);
    expect($reconcileResult['error'])->toBeNull();

    // 4. Verify CLI status, diagnose, and control in separate OS processes.
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    $scopeVersion = (int) DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('control_version');

    $cliResult = ConcurrentTransactions::execute(sprintf(<<<'PHP'
        \Illuminate\Support\Facades\Artisan::call('process:status', ['--platform' => 'eis']);
        $statusOutput = \Illuminate\Support\Facades\Artisan::output();

        \Illuminate\Support\Facades\Artisan::call('process:diagnose', ['platform' => 'eis', '--dry-run' => true]);
        $diagnoseExit = \Illuminate\Support\Facades\Artisan::call('process:diagnose', ['platform' => 'eis', '--dry-run' => true]);

        $pauseExit = \Illuminate\Support\Facades\Artisan::call('process:control', [
            'action' => 'pause',
            'target' => 'acquisition',
            '--user' => '%d',
            '--reason' => 'Multi-process CLI pause check',
            '--expected' => '%d',
        ]);
        $pauseOutput = \Illuminate\Support\Facades\Artisan::output();

        $resumeExit = \Illuminate\Support\Facades\Artisan::call('process:control', [
            'action' => 'resume',
            'target' => 'acquisition',
            '--user' => '%d',
            '--reason' => 'Multi-process CLI resume check',
            '--expected' => '%d',
        ]);
        $resumeOutput = \Illuminate\Support\Facades\Artisan::output();

        return [
            'pid' => (int) \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            'status_output' => $statusOutput,
            'diagnose_exit' => $diagnoseExit,
            'pause_exit' => $pauseExit,
            'pause_output' => $pauseOutput,
            'resume_exit' => $resumeExit,
            'resume_output' => $resumeOutput,
        ];
        PHP, $operator->id, $scopeVersion, $operator->id, $scopeVersion + 1));

    expect($cliResult['pid'])->not->toBe($parentPid)
        ->and($cliResult['status_output'])->toContain('work_transition_total v1;platform=eis;stage=listing;state=succeeded')
        ->and($cliResult['diagnose_exit'])->toBe(0)
        ->and($cliResult['pause_exit'])->toBe(0)
        ->and($cliResult['pause_output'])->toContain('pause: applied')
        ->and($cliResult['resume_exit'])->toBe(0)
        ->and($cliResult['resume_output'])->toContain('resume: applied')
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('desired_mode'))->toBe('enabled')
        ->and((int) DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('control_version'))->toBe($scopeVersion + 2);

    $snapshot = app(PlatformMetricsReadRepository::class)->read(new PlatformCode('eis'));
    expect($snapshot->counters)->not->toBeEmpty();

    // Verify no leftover jobs remain on Redis queues.
    foreach ([StageCode::Listing, StageCode::Detail] as $stage) {
        $route = app(StageQueueMap::class)->forStage($stage);
        expect(Queue::connection($route->connection)->pop($route->queue))->not->toBeInstanceOf(RedisJob::class);
    }
});
