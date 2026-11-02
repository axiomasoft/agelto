<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Procurement\Ai\AnalysisRecipe;
use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Document\DocumentRange;
use App\Dto\Procurement\Document\RepresentationCoverage;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\PublicationInput;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Document\CoverageUnit;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Services\Procurement\Extraction\ExtractionHandler;
use App\Services\Procurement\Extraction\PublicationHandler;
use App\Services\Procurement\Extraction\PublicationStageCodec;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\ChunkAssemblyFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\ExtractionRuntimeFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PublicationRuntimeFixtures;

pest()->group('t13', 't15', 't22', 'p313');

test('MAX_TOKENS checkpoints the split and both exact halves produce one complete business publication', function (): void {
    [$work, $lease, $window, $period] = ExtractionRuntimeFixtures::work(new RowRange(0, 4));
    $input = new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
    ExtractionRuntimeFixtures::capture($lease, $window, $period, '{discarded_prefix', 'MAX_TOKENS');
    app(WorkFinalizer::class)->finish($lease, app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    expect(DB::table('prc_analysis_chunks')->count())->toBe(0);
    foreach ([new RowRange(0, 2), new RowRange(2, 4)] as $range) {
        $lease = FinalizeFixtures::running($work);
        ExtractionRuntimeFixtures::capture($lease, $window, $period, ChunkAssemblyFixtures::chunk($window, $range)->canonicalRows, range: $range);
        app(WorkFinalizer::class)->finish($lease, app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    }
    $lease = FinalizeFixtures::running($work);
    app(WorkFinalizer::class)->finish($lease, app(ExtractionHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    $publication = new WorkId(new ProcessUuid(DB::table('prc_process_work')->where('stage', 'publication')->soleValue('id')));
    $lease = FinalizeFixtures::running($publication);
    $pubInput = new PublicationInput($window->run->id, $window->run->recipe->hash, 1);
    app(WorkFinalizer::class)->finish($lease, app(PublicationHandler::class)->execute($pubInput, app(ExecutionContextFactory::class)->open($lease)));
    expect(ExecutionFixtures::work($publication)->state)->toBe('succeeded')->and(DB::table('prc_estimates')->count())->toBe(1)
        ->and(DB::table('prc_estimate_items')->count())->toBe(4)->and(DB::table('prc_analysis_chunks')->count())->toBe(2)
        ->and(DB::table('llm_provider_requests')->count())->toBe(3)->and(DB::table('llm_budget_reservations')->count())->toBe(3)
        ->and(DB::table('prc_analysis_llm_requests')->where('recovery_active', true)->count())->toBe(0);
});

test('complete accepted chunks publish normalized estimates and all depth five flat totals with immutable provenance once', function (): void {
    [$work, $lease, $input, $window] = PublicationRuntimeFixtures::ready();
    $outcome = app(PublicationHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    $codec = new PublicationStageCodec;
    expect($codec->decodeResult($codec->encodeResult($outcome->result)))->toEqual($outcome->result);
    expect(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('succeeded')
        ->and(app(WorkFinalizer::class)->finish($lease, $outcome)->status->value)->toBe('lost_ownership')
        ->and(DB::table('prc_analysis_runs')->value('publication_status'))->toBe('published')
        ->and(DB::table('prc_document_versions')->where('id', $window->run->documentVersionId)->value('published_analysis_run_id'))->toBe($window->run->id->value)
        ->and(DB::table('prc_estimates')->where('analysis_run_id', $window->run->id->value)->count())->toBe(1)
        ->and(DB::table('prc_estimate_items')->count())->toBe(2)
        ->and(DB::table('prc_estimate_totals')->count())->toBe(6)
        ->and(DB::table('prc_estimates')->value('verified_at'))->toBeNull()
        ->and(ExecutionFixtures::work($work)->checkpoint)->toBeNull();
    $provenance = json_decode(DB::table('prc_estimate_items')->where('row_index', 0)->value('analysis_provenance'), true, flags: JSON_THROW_ON_ERROR);
    expect($provenance['source_anchor'])->toBe('p0:r0')->and($provenance['document_version_id'])->toBe($window->run->documentVersionId)
        ->and($provenance['chunk_ids'])->toBe([DB::table('prc_analysis_chunks')->soleValue('id')]);
    $parent = DB::table('prc_estimate_totals')->where('source_node_id', 'n4')->soleValue('id');
    expect(DB::table('prc_estimate_totals')->where('source_node_id', 'n5')->soleValue('parent_id'))->toBe($parent)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)->and(DB::table('llm_budget_reservations')->count())->toBe(1);
});

test('mandatory journal failure rolls back normalized rows publication status and current pointer together', function (): void {
    [$work, $lease, $input] = PublicationRuntimeFixtures::ready();
    $outcome = app(PublicationHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    DB::unprepared("CREATE FUNCTION fixture_publication_reject() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'publication journal failure'; END $$");
    DB::unprepared('CREATE TRIGGER fixture_publication_reject BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION fixture_publication_reject()');
    try {
        expect(fn () => app(WorkFinalizer::class)->finish($lease, $outcome))->toThrow(QueryException::class, 'publication journal failure');
        expect(DB::table('prc_estimates')->count())->toBe(0)->and(DB::table('prc_estimate_items')->count())->toBe(0)
            ->and(DB::table('prc_estimate_totals')->count())->toBe(0)->and(DB::table('prc_document_versions')->whereNotNull('published_analysis_run_id')->count())->toBe(0)
            ->and(DB::table('prc_analysis_runs')->value('publication_status'))->toBe('unpublished')
            ->and(ExecutionFixtures::work($work)->state)->toBe('running');
    } finally {
        DB::unprepared('DROP TRIGGER fixture_publication_reject ON prc_process_transitions');
        DB::unprepared('DROP FUNCTION fixture_publication_reject()');
    }
    expect(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('succeeded');
});

test('normalization retains exact decimal strings resources and section provenance without claiming operator verification', function (): void {
    $raw = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
    $raw['rows'][0]['row'] = ['row_index' => 0, 'row_type' => 'section_header', 'name' => 'Source section'];
    $raw['rows'][1]['row']['quantity'] = '1';
    $raw['rows'][1]['row']['unit_price_curr'] = '9007199254740993.010000000001';
    $raw['rows'][1]['row']['total_price_curr'] = '9007199254740993.010000000001';
    $raw['rows'][1]['row']['resources'] = [['resource_type' => 'material', 'name' => 'Precise resource', 'unit' => 'м', 'quantity' => '0.000000000001', 'price_curr' => '123.000000000001']];
    [$work,$lease,$input] = PublicationRuntimeFixtures::ready(json_encode($raw, JSON_THROW_ON_ERROR));
    app(WorkFinalizer::class)->finish($lease, app(PublicationHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
    expect(DB::table('prc_estimate_items')->soleValue('total_price_curr'))->toBe('9007199254740993.010000000001')
        ->and(DB::table('prc_estimate_resources')->soleValue('quantity'))->toBe('0.000000000001')
        ->and(DB::table('prc_estimate_resources')->soleValue('price_curr'))->toBe('123.000000000001')
        ->and(DB::table('prc_estimate_sections')->soleValue('title'))->toBe('Source section')
        ->and(DB::table('prc_estimate_items')->soleValue('section_id'))->toBe(DB::table('prc_estimate_sections')->soleValue('id'))
        ->and(DB::table('prc_estimate_sections')->whereNotNull('analysis_provenance')->count())->toBe(1)
        ->and(DB::table('prc_estimate_resources')->whereNotNull('analysis_provenance')->count())->toBe(1);
});

test('sealed independent source coverage cannot be replaced even before any chunk is accepted', function (): void {
    [$work,$lease,$window,$period] = ExtractionRuntimeFixtures::work();
    expect(fn () => DB::transaction(fn () => DB::update("UPDATE prc_analysis_runs SET declared_coverage='{}'::jsonb WHERE id=?", [$window->run->id->value])))
        ->toThrow(QueryException::class, 'Declared source coverage is immutable');
});

test('missing independent source declaration blocks authorization before any financial reservation', function (): void {
    [$work,$lease,$window,$period] = ExtractionRuntimeFixtures::work(sealPlan: false);
    expect(fn () => ExtractionRuntimeFixtures::capture($lease, $window, $period, AiFixtures::raw()))
        ->toThrow(OperationDenied::class, 'analysis_source_plan_invalid');
    expect(DB::table('llm_provider_requests')->count())->toBe(0)->and(DB::table('llm_budget_reservations')->count())->toBe(0);
});

test('hard cancellation head drift and a new pinned recipe fence an already validated publication', function (string $change): void {
    [$work, $lease, $input, $window] = PublicationRuntimeFixtures::ready();
    $outcome = app(PublicationHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    if ($change === 'cancel') {
        app(CancelWork::class)->execute($work, 'fixture publication cancellation');
    } elseif ($change === 'head') {
        DB::update('UPDATE prc_documents SET head_generation=head_generation+1 WHERE id=(SELECT document_id FROM prc_document_versions WHERE id=?)', [$window->run->documentVersionId]);
    } else {
        $old = $window->run;
        $recipe = $old->recipe;
        $newRecipe = new AnalysisRecipe($recipe->preparationHash, 'new-analyzer/1', $recipe->tasks, $recipe->llm, $recipe->coverage, $recipe->currency, $recipe->locale);
        DB::transaction(fn () => app(AnalysisRepository::class)->storeRunLocked(new AnalysisRunSnapshot(LedgerFixtures::uuid(), $old->documentVersionId,
            $old->representationId, $old->representationHash, $newRecipe, 1, $old->expectedDocumentHeadGeneration, $old->expectedAnalysisGeneration + 1, 'new recipe', $old->id)));
    }
    app(WorkFinalizer::class)->finish($lease, $outcome);
    expect(ExecutionFixtures::work($work)->state)->toBe($change === 'cancel' ? 'cancelled' : 'superseded')
        ->and(DB::table('prc_estimates')->count())->toBe(0)->and(DB::table('prc_document_versions')->whereNotNull('published_analysis_run_id')->count())->toBe(0);
})->with(['cancel', 'head', 'recipe']);

test('complete model rows cannot publish an incomplete source representation or an undeclared extra window', function (string $defect): void {
    $coverage = $defect === 'source' ? new RepresentationCoverage(CoverageUnit::Page, 2, [new DocumentRange(0, 1)], [new DocumentRange(1, 2)]) : null;
    [$work, $lease, $input, $window] = PublicationRuntimeFixtures::ready(coverage: $coverage);
    if ($defect === 'window') {
        DB::transaction(fn () => app(AnalysisRepository::class)->storeWindowLocked(new AnalysisWindowSnapshot(LedgerFixtures::uuid(), $window->run,
            'undeclared-window', new RowRange(2, 4), 1, ['p0:r2' => 0, 'p0:r3' => 0])));
    }
    $outcome = app(PublicationHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    app(WorkFinalizer::class)->finish($lease, $outcome);
    expect(ExecutionFixtures::work($work)->state)->toBe('blocked')->and(ExecutionFixtures::work($work)->reason_code)->toBe('extraction_incomplete')
        ->and(DB::table('prc_estimates')->count())->toBe(0)->and(DB::table('prc_document_versions')->whereNotNull('published_analysis_run_id')->count())->toBe(0);
})->with(['source', 'window']);

test('two PostgreSQL finalizers accept exactly one normalized business publication', function (): void {
    [$work, $lease, $input] = PublicationRuntimeFixtures::ready();
    $outcome = app(PublicationHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    $payload = base64_encode(serialize([$lease, $outcome]));
    $code = '\Tests\Support\ProcessCore\ExtractionRuntimeFixtures::bind();[$lease,$outcome]=unserialize(base64_decode('.var_export($payload, true).'));'
        .'$result=app(\App\Services\Procurement\Process\WorkFinalizer::class)->finish($lease,$outcome);'
        .'return ["status"=>$result->status->value,"pid"=>\Illuminate\Support\Facades\DB::selectOne("SELECT pg_backend_pid() AS id")->id];';
    $results = ConcurrentTransactions::concurrently(['first' => $code, 'second' => $code]);
    $statuses = [$results['first']['status'], $results['second']['status']];
    sort($statuses);
    expect($statuses)->toBe(['applied', 'lost_ownership'])->and($results['first']['pid'])->not->toBe($results['second']['pid'])
        ->and(DB::table('prc_estimates')->count())->toBe(1)->and(DB::table('prc_estimate_items')->count())->toBe(2)
        ->and(DB::table('prc_analysis_runs')->value('publication_status'))->toBe('published');
});
