<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Document\DocumentWorkRepository;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Document\DocumentFeeder;
use App\Services\Procurement\Extraction\AnalysisRuntime;
use App\Services\Procurement\Prepare\PdfPreparationTools;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureStagePayloadCodec;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PreparedFixtureAnalysisHandler;
use Tests\Support\ProcessCore\PreparedFixturePublicationRepository;
use Tests\Support\ProcessCore\StubPdfTools;

pest()->group('document-processing', 'document-storage-and-references');

beforeEach(function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    app()->instance(PdfPreparationTools::class, new StubPdfTools(count: 2));
    app()->forgetInstance(StageRegistry::class);
    $this->artisan('process:sync-documents')->assertSuccessful();
});

/** Fixture activation is explicit and local to this PHP test; the real pending intent stays pending. */
function documentFixtureScreeningWork(DocumentRepresentation $representation, string $cohort, int $platformId, ProcessUuid $run): WorkId
{
    $analysis = AiFixtures::pinScreening($representation->id);
    $input = new ScreeningInput(
        documentVersionId: $representation->document_version_id,
        representationHash: new ContentHash($representation->content_hash),
        screeningRecipeHash: $analysis->recipe->hash,
    );
    $work = new WorkId(LedgerFixtures::uuid());
    app(ProcessTransaction::class)->run(function () use ($work, $input, $analysis, $cohort, $platformId, $run): void {
        $works = app(WorkStoreRepository::class);
        $works->storeWork(id: $work, input: $input, inputRevision: $analysis->expectedAnalysisGeneration, intentId: null,
            cohortKey: $cohort, platformId: $platformId, originRunId: $run, priority: 100,
            eventId: LedgerFixtures::uuid(), projectionMessageId: LedgerFixtures::uuid());
        $works->linkRun(runId: $run, workId: $work, relation: RunWorkRelation::Created, role: AccountingRole::Required);
    });

    return $work;
}

test('подготовленные bytes двух площадок и manual проходят fixture publication с общими head run coverage guards', function (string $origin, string $boundary): void {
    $platform = Platform::factory()->create(['code' => 'p36-'.$origin, 'is_active' => false]);
    $procurement = $origin === 'manual' ? null : Procurement::factory()->create([
        'platform_id' => $platform->id,
        'registry_id' => Registry::factory()->create(['platform_id' => $platform->id])->id,
    ]);
    $document = Document::factory()->create(['platform_id' => $platform->id, 'procurement_id' => $procurement?->id]);
    $domain = DocumentStorageFixtures::domain();
    $revision = DocumentStorageFixtures::observe(
        head: new DocumentHead(documentId: $document->id, generation: 1, versionId: null),
        artifact: DocumentStorageFixtures::upload(bytes: "%PDF-1.4\nfixture source\n%%EOF", domain: $domain),
    );
    ExecutionFixtures::setMode(scopeKey: 'acquisition', mode: ControlMode::Paused);
    DB::table('prc_source_targets')->update(['retired_at' => now()]);
    expect(app(DocumentWorkRepository::class)->prepare($document->id))->toBe(1);
    $prepare = DB::table('prc_process_work')->where('stage', 'prepare')->sole();
    $prepareId = new WorkId(new ProcessUuid($prepare->id));
    for ($i = 0; $i < 4 && ExecutionFixtures::work($prepareId)->state !== 'succeeded'; $i++) {
        app(WorkFeeder::class)->tick('prepare');
        (new ExecuteWorkJob(workId: $prepare->id, dispatchGeneration: ExecutionFixtures::delivery($prepareId)->value,
            messageId: LedgerFixtures::uuid()->value))->handle(app(WorkRunner::class));
    }
    $representation = DocumentRepresentation::query()->where('range_key', 'complete')->sole();
    $text = "[page:1]\ncontent 1\n[page:2]\ncontent 2\n";
    $coverage = json_decode($representation->coverage_manifest, associative: true, flags: JSON_THROW_ON_ERROR);
    $cohort = $origin === 'manual' ? 'manual' : 'platform:'.$platform->id;
    expect(ExecutionFixtures::work($prepareId)->state)->toBe('succeeded')
        ->and($prepare->cohort_key)->toBe($cohort)
        ->and($representation->document_version_id)->toBe($revision->id)
        ->and($representation->content_hash)->toBe(hash('sha256', $text))
        ->and(Storage::disk($representation->disk)->get($representation->storage_path))->toBe($text)
        ->and($coverage['source_units'])->toBe(2)->and($coverage['covered'])->toBe([[0, 1], [1, 2]])
        ->and($coverage['missing'])->toBe([])->and($coverage['complete'])->toBeTrue()
        ->and(array_column($coverage['pages'], 'page'))->toBe([1, 2])
        ->and(array_column($coverage['pages'], 'status'))->toBe(['native_text', 'ocr_text'])
        ->and(DB::table('prc_process_run_work')->where('work_id', $prepare->id)->where('run_id', $prepare->origin_run_id)->value('accounting_role'))->toBe('required');
    $pending = DB::table('prc_operation_intents')->where('stage', 'screening')->sole();
    expect([$pending->state, $pending->pending_reason, $pending->subject_id, $pending->expected_head_generation])
        ->toBe(['pending', null, (string) $revision->id, $revision->head->generation])
        ->and(app(StageRegistry::class)->handlerVersion(StageCode::Screening))->toBe(AnalysisRuntime::VERSION);

    app()->instance(StageRegistry::class, new StageRegistry);
    ExecutionFixtures::authority([StageCode::Screening]);
    DB::statement('CREATE TEMP TABLE p36_fixture_publications(work_id uuid PRIMARY KEY,version_id bigint NOT NULL,disk text NOT NULL,object_key text NOT NULL,content_hash text NOT NULL)');
    $this->beforeApplicationDestroyed(fn () => DB::statement('DROP TABLE IF EXISTS p36_fixture_publications'));
    $handler = new PreparedFixtureAnalysisHandler($text);
    app(StageRegistry::class)->bindHandler(stage: StageCode::Screening, handler: $handler);
    $work = documentFixtureScreeningWork(representation: $representation, cohort: $cohort, platformId: $platform->id, run: new ProcessUuid($prepare->origin_run_id));
    app(StageRegistry::class)->bindFinalization(StageCode::Screening, new FixtureStagePayloadCodec(StageCode::Screening), new PreparedFixturePublicationRepository($work));
    $lease = FinalizeFixtures::running($work);
    $execution = new ExecutionContext(
        lease: $lease, keeper: app(WorkStoreRepository::class), clock: app(ProcessClock::class),
        slice: Duration::seconds(270), leaseDuration: Duration::seconds(390),
        heartbeatInterval: Duration::seconds(15), finalizationReserve: Duration::seconds(15),
        leaseSafety: Duration::seconds(30),
    );
    $input = app(AuthorityRepository::class)->readAuthoritySnapshot($work)->input;
    $outcome = $handler->execute(input: $input, execution: $execution);
    if ($boundary === 'new_head') {
        DocumentStorageFixtures::observe(head: $revision->head,
            artifact: DocumentStorageFixtures::upload(bytes: "%PDF-1.4\nnew source\n%%EOF", domain: $domain));
        $completion = app(WorkFinalizer::class)->finish(lease: $lease, outcome: $outcome);
        expect($completion->status)->toBe(CompletionStatus::Applied)
            ->and($completion->state)->toBe(WorkState::Superseded)
            ->and(DB::table('prc_process_reservations')->count())->toBe(0)
            ->and(DB::table('p36_fixture_publications')->count())->toBe(0)
            ->and(DB::table('prc_documents')->where('id', $document->id)->value('current_version_id'))->not->toBe($revision->id);
    } else {
        if ($boundary === 'journal_rollback') {
            DB::unprepared("CREATE FUNCTION p36_reject() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.entity_kind='work' AND NEW.new_state='succeeded' THEN RAISE EXCEPTION 'p36 journal failure'; END IF; RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER p36_reject BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION p36_reject()');
            try {
                expect(fn () => app(WorkFinalizer::class)->finish(lease: $lease, outcome: $outcome))->toThrow(QueryException::class, 'p36 journal failure');
                expect(DB::table('prc_process_work')->where('id', $work->value->value)->value('state'))->toBe('running')
                    ->and(DB::table('p36_fixture_publications')->count())->toBe(0)
                    ->and(DB::table('prc_process_reservations')->where('work_id', $work->value->value)->count())->toBe(2);
            } finally {
                DB::unprepared('DROP TRIGGER p36_reject ON prc_process_transitions');
                DB::unprepared('DROP FUNCTION p36_reject()');
            }
        }
        expect(app(WorkFinalizer::class)->finish(lease: $lease, outcome: $outcome)->status)->toBe(CompletionStatus::Applied);
        $accepted = DB::table('prc_process_work')->where('id', $work->value->value)->sole();
        $result = DB::table('p36_fixture_publications')->sole();
        expect($accepted->state)->toBe('succeeded')
            ->and([$result->work_id, $result->version_id, $result->disk, $result->object_key, $result->content_hash])
            ->toBe([$work->value->value, $revision->id, $representation->disk, $representation->storage_path, hash('sha256', $text)]);
        (new ExecuteWorkJob(workId: $work->value->value, dispatchGeneration: ExecutionFixtures::delivery($work)->value,
            messageId: LedgerFixtures::uuid()->value))->handle(app(WorkRunner::class));
        expect(app(WorkFinalizer::class)->finish(lease: $lease, outcome: $outcome)->status)->toBe(CompletionStatus::LostOwnership)
            ->and(DB::table('p36_fixture_publications')->count())->toBe(1)->and($handler->calls)->toBe(1)
            ->and(DB::table('prc_process_transitions')->where('work_id', $work->value->value)->where('new_state', 'succeeded')->count())->toBe(1)
            ->and(DB::table('prc_process_reservations')->count())->toBe(0);
    }
    expect(DB::table('prc_operation_intents')->where('id', $pending->id)->value('pending_reason'))->toBeNull()
        ->and(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and(DB::table('prc_estimates')->count())->toBe(0)
        ->and(DB::table('prc_analysis_runs')->count())->toBe(1)
        ->and(app(DocumentFeeder::class)->reconcile())->toBe($boundary === 'new_head' ? 1 : 0);
})->with(['eis', 'json', 'manual'])->with(['complete', 'new_head', 'journal_rollback']);
