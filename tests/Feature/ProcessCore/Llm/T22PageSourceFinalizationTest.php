<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Process\NoProviderRecovery;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\StageBlocked;
use App\Enums\Procurement\Process\BlockedReason;
use App\Repositories\Procurement\Ai\AnalysisPlanRepository;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Screening\ScreeningHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\PageRuntimeFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

pest()->group('t13', 't15', 't22', 'p313');

test('database отклоняет page codec без source manifest ещё до первого provider call', function (): void {
    [$work, $lease, $run] = PageRuntimeFixtures::work();
    expect(fn () => DB::transaction(fn () => DB::insert("INSERT INTO prc_analysis_runs
        (id,document_version_id,representation_id,representation_hash,recipe_json,recipe_schema_version,recipe_hash,generation,
         expected_document_head_generation,expected_analysis_generation,requested_reason,publication_status,declared_coverage,declared_coverage_schema_version)
        SELECT ?,document_version_id,representation_id,representation_hash,recipe_json,recipe_schema_version,recipe_hash,generation,
            expected_document_head_generation,expected_analysis_generation,'invalid source fixture','unpublished','{\"estimates\":null}'::jsonb,2
        FROM prc_analysis_runs WHERE id=?", [(string) Str::uuid(), $run->id->value])))->toThrow(QueryException::class, 'prc_analysis_runs_coverage_source');
    expect(DB::table('prc_analysis_runs')->count())->toBe(1)
        ->and(DB::table('llm_provider_calls')->count())->toBe(0);
});

test('pinned discovery split и raw recovery создают окна всех смет без второго send', function (): void {
    [$work, $lease, $run, $period] = PageRuntimeFixtures::work();
    $input = new ScreeningInput($run->documentVersionId, $run->representationHash, $run->recipe->hash);
    $apply = static function ($lease) use ($input) {
        return app(WorkFinalizer::class)->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    };
    expect(DB::table('prc_analysis_windows')->count())->toBe(0);
    PageRuntimeFixtures::capture($lease, $run, $period, ScreeningRuntimeFixtures::raw());
    $apply($lease);
    $lease = FinalizeFixtures::running($work);
    PageRuntimeFixtures::capture($lease, $run, $period, '{"pages":[]}', new PageRange(0, 4), 'MAX_TOKENS');
    $apply($lease);
    $lease = FinalizeFixtures::running($work);
    expect($lease->checkpoint->stageProgress->discovery->pending)->toEqual([new PageRange(0, 2), new PageRange(2, 4)]);
    $left = json_encode(['pages' => [['page_index' => 0, 'estimate_ordinal' => 1], ['page_index' => 1, 'estimate_ordinal' => 1]], 'ambiguous_pages' => []], JSON_THROW_ON_ERROR);
    PageRuntimeFixtures::capture($lease, $run, $period, $left, new PageRange(0, 2));
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $lease = FinalizeFixtures::running($work);
    expect($lease->checkpoint->providerRecoveryPlan)->toBeInstanceOf(ProviderRecoveryPlan::class);
    $apply($lease);
    $lease = FinalizeFixtures::running($work);
    expect($lease->checkpoint->providerRecoveryPlan)->toBeInstanceOf(NoProviderRecovery::class)
        ->and($lease->checkpoint->stageProgress->discovery->pending)->toEqual([new PageRange(2, 4)])
        ->and(DB::table('llm_provider_calls')->count())->toBe(3);
    PageRuntimeFixtures::capture($lease, $run, $period, json_encode(['pages' => [['page_index' => 2, 'estimate_ordinal' => 2], ['page_index' => 3, 'estimate_ordinal' => 2]], 'ambiguous_pages' => []], JSON_THROW_ON_ERROR), new PageRange(2, 4));
    $apply($lease);
    $lease = FinalizeFixtures::running($work);
    expect($apply($lease)->state?->value)->toBe('succeeded');
    $plans = app(AnalysisPlanRepository::class)->plans($run);
    expect(array_map(static fn ($p) => $p->required, $plans))->toEqual([new PageRange(0, 2), new PageRange(2, 4)])
        ->and(DB::table('prc_analysis_windows')->count())->toBe(2)
        ->and(DB::table('prc_process_work')->where('stage', 'extraction')->count())->toBe(2)
        ->and(DB::table('llm_provider_calls')->count())->toBe(4)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(4)
        ->and(DB::table('prc_estimates')->count())->toBe(0)
        ->and(ExecutionFixtures::work($work)->checkpoint)->toBeNull();
    expect(app(WorkFinalizer::class)->finish($lease, new StageBlocked(BlockedReason::PolicyReview))->status->value)->toBe('lost_ownership');
    Http::assertNothingSent();
});

test('discovery gap reask ограничен одним ordinal между доставками', function (): void {
    [$work, $lease, $run, $period] = PageRuntimeFixtures::work();
    $input = new ScreeningInput($run->documentVersionId, $run->representationHash, $run->recipe->hash);
    $apply = static fn ($lease) => app(WorkFinalizer::class)->finish($lease,
        app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    PageRuntimeFixtures::capture($lease, $run, $period, ScreeningRuntimeFixtures::raw());
    $apply($lease);
    for ($ordinal = 0; $ordinal <= 1; $ordinal++) {
        $lease = FinalizeFixtures::running($work);
        PageRuntimeFixtures::capture($lease, $run, $period, '{"pages":[{"page_index":0,"estimate_ordinal":1}],"ambiguous_pages":[]}', new PageRange(0, 4), ordinal: $ordinal);
        $apply($lease);
    }
    $lease = FinalizeFixtures::running($work);
    $apply($lease);
    expect(ExecutionFixtures::work($work)->state)->toBe('blocked')
        ->and(ExecutionFixtures::work($work)->reason_code)->toBe('semantic_review_required')
        ->and(ExecutionFixtures::work($work)->item_failures)->toBe(0)
        ->and(DB::table('llm_provider_requests')->count())->toBe(3)
        ->and(DB::table('llm_provider_requests')->max('semantic_reask_ordinal'))->toBe(1)
        ->and(DB::table('prc_analysis_windows')->count())->toBe(0)
        ->and(DB::table('prc_estimates')->count())->toBe(0);
});

test('source seal и создание окон откатываются вместе с обязательным journal', function (): void {
    [$work, $lease, $run, $period] = PageRuntimeFixtures::work();
    $input = new ScreeningInput($run->documentVersionId, $run->representationHash, $run->recipe->hash);
    $outcome = static fn ($lease) => app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    PageRuntimeFixtures::capture($lease, $run, $period, ScreeningRuntimeFixtures::raw());
    app(WorkFinalizer::class)->finish($lease, $outcome($lease));
    $lease = FinalizeFixtures::running($work);
    $pages = array_map(static fn (int $p): array => ['page_index' => $p, 'estimate_ordinal' => $p < 2 ? 1 : 2], range(0, 3));
    PageRuntimeFixtures::capture($lease, $run, $period, json_encode(['pages' => $pages, 'ambiguous_pages' => []], JSON_THROW_ON_ERROR), new PageRange(0, 4));
    app(WorkFinalizer::class)->finish($lease, $outcome($lease));
    $lease = FinalizeFixtures::running($work);
    $success = $outcome($lease);
    DB::unprepared("CREATE FUNCTION fixture_discovery_journal_reject() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'fixture discovery journal failure'; END \$\$");
    DB::unprepared('CREATE TRIGGER fixture_discovery_journal_reject BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION fixture_discovery_journal_reject()');
    try {
        expect(fn () => app(WorkFinalizer::class)->finish($lease, $success))->toThrow(QueryException::class, 'fixture discovery journal failure');
        expect(DB::table('prc_analysis_windows')->count())->toBe(0)
            ->and(DB::table('prc_process_work')->where('stage', 'extraction')->count())->toBe(0)
            ->and(DB::selectOne("SELECT declared_coverage->'estimates' AS estimates FROM prc_analysis_runs")->estimates)->toBe('null');
    } finally {
        DB::unprepared('DROP TRIGGER fixture_discovery_journal_reject ON prc_process_transitions');
        DB::unprepared('DROP FUNCTION fixture_discovery_journal_reject()');
    }
    expect(app(WorkFinalizer::class)->finish($lease, $success)->state?->value)->toBe('succeeded')
        ->and(DB::table('prc_analysis_windows')->count())->toBe(2);
    expect(fn () => DB::update("UPDATE prc_analysis_runs SET declared_coverage=jsonb_set(declared_coverage,'{source,page_count}','99')"))->toThrow(QueryException::class);
});
