<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Procurement\Ai\AnalysisRecipe;
use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\ScreeningProgress;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\NoProviderRecovery;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Screening\ScreeningHandler;
use App\Services\Procurement\Screening\ScreeningStageCodec;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

pest()->group('t13', 't15', 't22', 'p313');

test('screening raw recovery preserves its directive through partial progress and accepts without another paid call', function (): void {
    [$work, $lease, $window, $period] = ScreeningRuntimeFixtures::work();
    ScreeningRuntimeFixtures::capture($lease, $window, $period, ScreeningRuntimeFixtures::raw());
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $lease = FinalizeFixtures::running($work);
    $plan = $lease->checkpoint->providerRecoveryPlan;
    expect($plan)->toBeInstanceOf(ProviderRecoveryPlan::class);
    app(WorkFinalizer::class)->finish($lease, new StagePartial(new ScreeningProgress, new MissingParts(['slice'])));
    $lease = FinalizeFixtures::running($work);
    expect($lease->checkpoint->providerRecoveryPlan)->toEqual($plan);
    $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
    $outcome = app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    expect($outcome)->toBeInstanceOf(StagePartial::class);
    app(WorkFinalizer::class)->finish($lease, $outcome);
    $lease = FinalizeFixtures::running($work);
    expect($lease->checkpoint->providerRecoveryPlan)->toBeInstanceOf(NoProviderRecovery::class);
    $outcome = app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    expect(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('succeeded')
        ->and(DB::table('prc_analysis_runs')->value('screening_disposition'))->toBe('extract')
        ->and(DB::table('prc_analysis_runs')->value('screening_result_schema_version'))->toBe(1)
        ->and(DB::table('prc_process_work')->where('stage', 'extraction')->count())->toBe(1)
        ->and(DB::table('prc_analysis_llm_requests')->where('recovery_active', true)->count())->toBe(0)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
        ->and(ExecutionFixtures::work($work)->checkpoint)->toBeNull();
    Http::assertNothingSent();
});

test('screening bounded schema and semantic reasks survive deliveries and leave item failures untouched', function (string $defect, string $reason): void {
    [$work, $lease, $window, $period] = ScreeningRuntimeFixtures::work();
    $raw = json_decode(ScreeningRuntimeFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
    if ($defect === 'schema') {
        $raw['unexpected'] = true;
    } else {
        $raw['estimate_cardinality'] = 'none';
    }
    $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
    for ($ordinal = 0; $ordinal < 2; $ordinal++) {
        ScreeningRuntimeFixtures::capture($lease, $window, $period, json_encode($raw, JSON_THROW_ON_ERROR), $ordinal);
        $outcome = app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
        app(WorkFinalizer::class)->finish($lease, $outcome);
        $lease = FinalizeFixtures::running($work);
    }
    app(WorkFinalizer::class)->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    expect(ExecutionFixtures::work($work)->state)->toBe('blocked')
        ->and(ExecutionFixtures::work($work)->reason_code)->toBe($reason)
        ->and(ExecutionFixtures::work($work)->item_failures)->toBe(0)
        ->and(DB::table('llm_provider_requests')->count())->toBe(2)
        ->and(DB::table('llm_provider_requests')->max('semantic_reask_ordinal'))->toBe(1)
        ->and(DB::table('prc_analysis_runs')->value('screening_result'))->toBeNull()
        ->and(DB::table('prc_process_work')->where('stage', 'extraction')->count())->toBe(0);
})->with([['schema', 'ai_response_invalid'], ['semantic', 'semantic_review_required']]);

test('screening journal failure rolls back receipt consumption and accepted progress', function (): void {
    [$work, $lease, $window, $period] = ScreeningRuntimeFixtures::work();
    ScreeningRuntimeFixtures::capture($lease, $window, $period, ScreeningRuntimeFixtures::raw());
    $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
    $outcome = app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    DB::unprepared("CREATE FUNCTION fixture_screening_reject() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'fixture screening journal failure'; END \$\$");
    DB::unprepared('CREATE TRIGGER fixture_screening_reject BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION fixture_screening_reject()');
    try {
        expect(fn () => app(WorkFinalizer::class)->finish($lease, $outcome))->toThrow(QueryException::class, 'fixture screening journal failure');
        expect(ExecutionFixtures::work($work)->state)->toBe('running')
            ->and(DB::table('prc_analysis_llm_requests')->where('recovery_active', true)->count())->toBe(1)
            ->and(DB::table('prc_analysis_runs')->value('screening_result'))->toBeNull();
    } finally {
        DB::unprepared('DROP TRIGGER fixture_screening_reject ON prc_process_transitions');
        DB::unprepared('DROP FUNCTION fixture_screening_reject()');
    }
    expect(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('ready');
});

test('stale head recipe or cancel rejects a late screening result without creating extraction work', function (string $change): void {
    [$work, $lease, $window, $period] = ScreeningRuntimeFixtures::work();
    ScreeningRuntimeFixtures::capture($lease, $window, $period, ScreeningRuntimeFixtures::raw());
    $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
    $outcome = app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    if ($change === 'cancel') {
        app(CancelWork::class)->execute($work, 'fixture cancel');
    } elseif ($change === 'recipe') {
        $old = $window->run;
        $recipe = $old->recipe;
        $newRecipe = new AnalysisRecipe($recipe->preparationHash, $recipe->analyzerVersion, $recipe->tasks,
            $recipe->llm, $recipe->coverage, $recipe->currency, 'en-US', $recipe->screening);
        $run = new AnalysisRunSnapshot(LedgerFixtures::uuid(), $old->documentVersionId,
            $old->representationId, $old->representationHash, $newRecipe, 1, $old->expectedDocumentHeadGeneration, $old->expectedAnalysisGeneration + 1,
            'changed recipe', $old->id);
        app(ProcessTransaction::class)->run(function () use ($run): void {
            DB::select('SELECT id FROM prc_documents WHERE current_version_id=? FOR UPDATE', [$run->documentVersionId]);
            DB::select('SELECT id FROM prc_document_versions WHERE id=? FOR UPDATE', [$run->documentVersionId]);
            app(AnalysisRepository::class)->storeRunLocked($run);
        });
    } else {
        DB::update('UPDATE prc_documents SET head_generation=head_generation+1 WHERE current_version_id=?', [$input->documentVersionId]);
    }
    app(WorkFinalizer::class)->finish($lease, $outcome);
    expect(ExecutionFixtures::work($work)->state)->toBe($change === 'cancel' ? 'cancelled' : 'superseded')
        ->and(DB::table('prc_analysis_runs')->value('screening_result'))->toBeNull()
        ->and(DB::table('prc_process_work')->where('stage', 'extraction')->count())->toBe(0)
        ->and(DB::table('llm_budget_reservations')->value('state'))->toBe('settled');
})->with(['head', 'recipe', 'cancel']);

test('two PostgreSQL finalizers publish one screening result and one successor', function (): void {
    [$work, $lease, $window, $period] = ScreeningRuntimeFixtures::work();
    ScreeningRuntimeFixtures::capture($lease, $window, $period, ScreeningRuntimeFixtures::raw());
    $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
    app(WorkFinalizer::class)->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $lease = FinalizeFixtures::running($work);
    $outcome = app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    $payload = base64_encode(serialize([$lease, $outcome]));
    $code = '\Tests\Support\ProcessCore\ScreeningRuntimeFixtures::bind();'
        .'[$lease,$outcome]=unserialize(base64_decode('.var_export($payload, true).'));'
        .'$result=app(\App\Services\Procurement\Process\WorkFinalizer::class)->finish($lease,$outcome);'
        .'return ["status"=>$result->status->value,"pid"=>\Illuminate\Support\Facades\DB::selectOne("SELECT pg_backend_pid() AS id")->id];';
    $results = ConcurrentTransactions::concurrently(['first' => $code, 'second' => $code]);
    $statuses = [$results['first']['status'], $results['second']['status']];
    sort($statuses);
    expect($statuses)->toBe(['applied', 'lost_ownership'])
        ->and($results['first']['pid'])->not->toBe($results['second']['pid'])
        ->and(DB::table('prc_analysis_runs')->value('screening_disposition'))->toBe('extract')
        ->and(DB::table('prc_process_work')->where('stage', 'extraction')->count())->toBe(1)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1);
});

test('screening schema pairs and immutability reject invalid or replaced results', function (): void {
    [$work, $lease, $window, $period] = ScreeningRuntimeFixtures::work();
    expect(fn () => DB::transaction(fn () => DB::update('UPDATE prc_analysis_runs SET screening_result_schema_version=1 WHERE id=?', [$window->run->id->value])))
        ->toThrow(QueryException::class);
    ScreeningRuntimeFixtures::capture($lease, $window, $period, ScreeningRuntimeFixtures::raw());
    $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
    app(WorkFinalizer::class)->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $lease = FinalizeFixtures::running($work);
    app(WorkFinalizer::class)->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    expect(fn () => DB::transaction(fn () => DB::update("UPDATE prc_analysis_runs SET screening_disposition='ignore' WHERE id=?", [$window->run->id->value])))
        ->toThrow(QueryException::class, 'Accepted screening is immutable');
    $codec = new ScreeningStageCodec;
    expect(fn () => $codec->decodeCheckpoint(new VersionedPayload('{"reask_ordinal":2,"result":null,"blocked":null}', 1)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $codec->decodeCheckpoint(new VersionedPayload('{"reask_ordinal":0,"result":{"unexpected":true},"blocked":null}', 1)))
        ->toThrow(InvalidArgumentException::class);
});
