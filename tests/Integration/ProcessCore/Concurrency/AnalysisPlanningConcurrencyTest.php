<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\AnalysisProviderConnection;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\ResponseKind;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\AiUiFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PageRuntimeFixtures;

pest()->group('claim-recovery-and-invariants', 'analysis-execution');

test('два producer после одного pre-read сериализуют version и создают ровно один analysis', function (): void {
    [$candidate, $document, $period] = PageRuntimeFixtures::prepared();
    $connection = new AnalysisProviderConnection($candidate->recipe->llm, 'https://fixture.test', FinancialFixtures::pricing(), $period,
        new RequestProfile('fixture.analysis', 1, 2, 3, 8000000, null, 0, 1, 1, [], ResponseKind::Binary, null, 1), 'fixture-only');
    $payload = var_export(base64_encode(serialize($connection)), true);
    $code = '\Illuminate\Support\Facades\DB::statement("SET application_name=\'p313-analysis-planning\'");'
        .'app(\App\Services\Procurement\Extraction\AnalysisProviderConnections::class)->register(unserialize(base64_decode('.$payload.')),preferred:true);'
        .'$count=app(\App\Services\Procurement\Extraction\AnalysisFeeder::class)->reconcile();'
        .'return ["created"=>$count,"pid"=>\Illuminate\Support\Facades\DB::selectOne("SELECT pg_backend_pid() AS id")->id];';
    $key = 831313;
    DB::unprepared('CREATE FUNCTION fixture_analysis_planning_barrier() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_advisory_xact_lock_shared(831313); RETURN NEW; END $$');
    DB::unprepared('CREATE TRIGGER fixture_analysis_planning_barrier BEFORE INSERT ON prc_analysis_runs FOR EACH ROW EXECUTE FUNCTION fixture_analysis_planning_barrier()');
    DB::select('SELECT pg_advisory_lock(?)', [$key]);
    $observed = [];
    try {
        $results = ConcurrentTransactions::concurrently(['first' => $code, 'second' => $code], function () use ($key, &$observed): void {
            try {
                $deadline = hrtime(true) + 5_000_000_000;
                do {
                    $observed = DB::select("SELECT pid,wait_event,query FROM pg_stat_activity WHERE application_name='p313-analysis-planning' AND wait_event_type='Lock'");
                    if (count($observed) === 2) {
                        break;
                    }
                    usleep(10000);
                } while (hrtime(true) < $deadline);
            } finally {
                DB::select('SELECT pg_advisory_unlock(?)', [$key]);
            }
        });
    } finally {
        DB::select('SELECT pg_advisory_unlock(?)', [$key]);
        DB::unprepared('DROP TRIGGER fixture_analysis_planning_barrier ON prc_analysis_runs');
        DB::unprepared('DROP FUNCTION fixture_analysis_planning_barrier()');
    }
    expect($observed)->toHaveCount(2)
        ->and(count(array_filter($observed, static fn ($r): bool => str_contains($r->query, 'FROM prc_documents') && $r->wait_event !== 'advisory')))->toBe(1)
        ->and(count(array_filter($observed, static fn ($r): bool => $r->wait_event === 'advisory')))->toBe(1)
        ->and($results['first']['pid'])->not->toBe($results['second']['pid'])
        ->and($results['first']['created'] + $results['second']['created'])->toBe(1)
        ->and(DB::table('prc_analysis_runs')->count())->toBe(1)
        ->and(DB::table('prc_process_work')->where('stage', 'screening')->count())->toBe(1)
        ->and(DB::table('prc_operation_intents')->where('stage', 'screening')->where('state', 'active')->count())->toBe(1)
        ->and(DB::table('llm_provider_requests')->count())->toBe(0)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(0);
});

test('concurrent manual commands serialize the current analysis and deduplicate only the same command', function (bool $sameCommand): void {
    [, $run, $analysis] = AiUiFixtures::accepted();
    $snapshot = app(AnalysisRepository::class)->getRun(new ProcessUuid($run->id));
    $connection = new AnalysisProviderConnection($snapshot->recipe->llm, 'https://fixture.test', FinancialFixtures::pricing(),
        DB::table('llm_budget_periods')->soleValue('id'),
        new RequestProfile('fixture.analysis', 1, 2, 3, 8000000, null, 0, 1, 1, [], ResponseKind::Binary, null, 1), 'fixture-only');
    $payload = var_export(base64_encode(serialize($connection)), true);
    $command = LedgerFixtures::uuid()->value;
    $code = static fn (string $id): string => '\Illuminate\Support\Facades\DB::statement("SET application_name=\'p313-manual-reanalysis\'");'
        .'app(\App\Services\Procurement\Extraction\AnalysisProviderConnections::class)->register(unserialize(base64_decode('.$payload.')),preferred:true);'
        .'try {$work=app(\App\Services\Procurement\Extraction\RerunAnalysisAction::class)->run('
        .'\App\Models\Procurement\Ai\Analysis::query()->findOrFail('.$analysis->id.'),new \App\Dto\Procurement\Process\ProcessUuid('.var_export($id, true).'));'
        .'$result=["work"=>$work->value->value,"rejected"=>false];} catch (\InvalidArgumentException $e) {'
        .'if(!str_contains($e->getMessage(),"current source")){throw $e;}$result=["work"=>null,"rejected"=>true];}'
        .'return $result+["pid"=>\Illuminate\Support\Facades\DB::selectOne("SELECT pg_backend_pid() AS id")->id];';
    $key = 831314;
    DB::unprepared('CREATE FUNCTION fixture_manual_analysis_barrier() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_advisory_xact_lock_shared(831314); RETURN NEW; END $$');
    DB::unprepared('CREATE TRIGGER fixture_manual_analysis_barrier BEFORE INSERT ON prc_analysis_runs FOR EACH ROW EXECUTE FUNCTION fixture_manual_analysis_barrier()');
    DB::select('SELECT pg_advisory_lock(?)', [$key]);
    $observed = [];
    try {
        $results = ConcurrentTransactions::concurrently(['first' => $code($command), 'second' => $code($sameCommand ? $command : LedgerFixtures::uuid()->value)],
            function () use ($key, &$observed): void {
                try {
                    $deadline = hrtime(true) + 5_000_000_000;
                    do {
                        $observed = DB::select("SELECT pid,wait_event,query FROM pg_stat_activity WHERE application_name='p313-manual-reanalysis' AND wait_event_type='Lock'");
                        if (count($observed) === 2) {
                            break;
                        }
                        usleep(10000);
                    } while (hrtime(true) < $deadline);
                } finally {
                    DB::select('SELECT pg_advisory_unlock(?)', [$key]);
                }
            });
    } finally {
        DB::select('SELECT pg_advisory_unlock(?)', [$key]);
        DB::unprepared('DROP TRIGGER fixture_manual_analysis_barrier ON prc_analysis_runs');
        DB::unprepared('DROP FUNCTION fixture_manual_analysis_barrier()');
    }
    expect($observed)->toHaveCount(2)
        ->and(count(array_filter($observed, static fn ($r): bool => $r->wait_event === 'advisory')))->toBe(1)
        ->and(count(array_filter($observed, static fn ($r): bool => str_contains($r->query, 'FROM prc_documents') && $r->wait_event !== 'advisory')))->toBe(1)
        ->and($results['first']['pid'])->not->toBe($results['second']['pid'])
        ->and(count(array_filter($results, static fn ($r): bool => $r['rejected'])))->toBe($sameCommand ? 0 : 1)
        ->and(count(array_unique(array_filter(array_column($results, 'work')))))->toBe(1)
        ->and(DB::table('prc_analysis_runs')->count())->toBe(2)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->count())->toBe(1)
        ->and(DB::table('prc_operation_intents')->where('state', 'active')->count())->toBe(1)
        ->and(DB::table('llm_provider_requests')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
        ->and($analysis->fresh()->analysis_run_id)->toBe($run->id);
})->with(['same command' => true, 'different commands' => false]);
