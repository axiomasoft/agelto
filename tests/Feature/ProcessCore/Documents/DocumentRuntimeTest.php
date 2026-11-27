<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\Download\DocumentReference;
use App\Dto\Procurement\Document\Download\SourceDocumentKey;
use App\Dto\Procurement\Network\Http\TransferBudget;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierType;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierValue;
use App\Dto\Procurement\Platform\Identity\SourceIdentity;
use App\Dto\Procurement\Process\Execution\StoreWorkData;
use App\Dto\Procurement\Process\Inputs\ScreeningInput;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Document\DocumentCatalogRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Procurement\Document\DocumentFeeder;
use App\Services\Procurement\Document\DocumentRuntime;
use App\Services\Procurement\Document\RuntimeDownloadHandler;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Prepare\Tools\PdfPreparationTools;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Process\Execution\WorkRunner;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use App\Services\Procurement\Proxyma\Caller\CallerOutbound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\DownloadFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FixtureStagePayloadCodec;
use Tests\Support\ProcessCore\HistoricalRouteCatalog as RouteRepository;
use Tests\Support\ProcessCore\Http\CallerFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PackageNetworkFacts;
use Tests\Support\ProcessCore\PreparedFixtureAnalysisHandler;
use Tests\Support\ProcessCore\PreparedFixturePublicationRepository;
use Tests\Support\ProcessCore\PrepareFixtures;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;
use Tests\Support\ProcessCore\ProxymaFixtures;
use Tests\Support\ProcessCore\StubPdfTools;

pest()->group('document-processing', 'admission-fairness');

test('consumer transport сохраняет download validators и отбрасывает посторонние response headers', function (): void {
    PermitFixtures::clean();
    $wire = new WireFixture;
    try {
        $f = new DownloadFixtures($wire);
        [, , $context] = $f->running();
        $wire->respond('/document', "%PDF-1.4\nfixture\n%%EOF", headers: [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="fixture.pdf"',
            'ETag' => '"fixture-v1"', 'Last-Modified' => 'Wed, 11 Nov 2026 10:00:00 GMT',
            'Set-Cookie' => 'fixture-secret', 'X-Private-Metadata' => 'fixture-secret',
        ]);
        $result = app(CallerOutbound::class)->execute($f->plan('/document')->request,
            $context, $f->network->gate(), $wire->policy(), new TransferBudget(262144, 262144));
        expect($result)->toBeInstanceOf(TransportSucceeded::class)
            ->and($result->response->header('Content-Disposition'))->toBe('attachment; filename="fixture.pdf"')
            ->and($result->response->header('ETag'))->toBe('"fixture-v1"')
            ->and($result->response->header('Last-Modified'))->toBe('Wed, 11 Nov 2026 10:00:00 GMT')
            ->and($result->response->header('Set-Cookie'))->toBeNull()
            ->and($result->response->header('X-Private-Metadata'))->toBeNull()
            ->and($wire->receipts())->toHaveCount(1)
            ->and(PackageNetworkFacts::attempts()->count())->toBe(1);
    } finally {
        $wire->stop();
        PermitFixtures::clean();
    }
});

test('потеря source object при prepare даёт storage wait без item failure и handler fault', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $f = new PrepareFixtures;
    $blob = DB::table('prc_document_blobs')->sole();
    Storage::disk($blob->disk)->delete($blob->object_key);
    app(StageRegistry::class)->bindHandler(StageCode::Prepare, $f->handler(new StubPdfTools(1)));
    app(WorkFeeder::class)->tick('prepare');
    (new ExecuteWorkJob($f->work->value->value, ExecutionFixtures::delivery($f->work)->value, LedgerFixtures::uuid()->value))
        ->handle(app(WorkRunner::class));
    $work = DB::table('prc_process_work')->where('id', $f->work->value->value)->sole();
    expect([$work->state, $work->reason_code, $work->item_failures])->toBe(['deferred', 'storage_unavailable', 0])
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->id)->value('fault_signature'))->toBeNull()
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and(DB::table('prc_document_representations')->count())->toBe(0);
});

test('registered runtime проводит reference download prepare через sole Process job затем явно держит real AI', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $wire = new WireFixture;
    try {
        $registry = CallerFixtures::platform($wire);
        $routeId = DB::table('prc_network_routes')->sole()->id;
        app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->ensureProfile(app(OutboundPolicy::class)->defaultRouteProfile, $routeId));
        CallerFixtures::healthReady();
        app()->instance(PdfPreparationTools::class, new StubPdfTools(1));
        ProxymaCallerFixtures::install($wire);
        app()->instance(RuntimeDownloadHandler::class, app()->makeWith(RuntimeDownloadHandler::class, ['urls' => $wire->policy()]));
        app()->forgetInstance(StageRegistry::class);
        expect(app(StageRegistry::class)->handlerVersion(StageCode::Download))->toBe(DocumentRuntime::VERSION);
        $this->artisan('process:sync-documents')->assertSuccessful();
        $this->artisan('process:sync-documents')->assertSuccessful();
        $procurement = Procurement::factory()->create(['platform_id' => $registry->platform_id, 'registry_id' => $registry->id]);
        $ref = new DocumentReference(new SourceIdentity(new PlatformCode('eis'), new SourceIdentifierType($procurement->source_identity_type),
            new SourceIdentifierValue($procurement->source_identity_value)), 'fixture', 'fixture.pdf', $wire->url('/document'), new SourceDocumentKey('fixture:document', 1));
        DB::transaction(fn () => app(DocumentCatalogRepository::class)->upsertReference($procurement, $ref, ContentHash::of('access')));
        $wire->respond('/document', "%PDF-1.4\nfixture\n%%EOF", headers: ['Content-Type' => 'application/pdf']);
        expect(app(DocumentFeeder::class)->reconcile())->toBe(1);
        $work = DB::table('prc_process_work')->where('stage', 'download')->sole();
        expect(app(WorkFeeder::class)->tick('download'))->toBe(1);
        (new ExecuteWorkJob($work->id, 1, LedgerFixtures::uuid()->value))->handle(app(WorkRunner::class));
        expect(DB::table('prc_process_work')->where('id', $work->id)->value('state'))->toBe('succeeded');
        $prepare = DB::table('prc_process_work')->where('stage', 'prepare')->sole();
        expect($prepare->input_revision)->toBe(2)->and($prepare->origin_run_id)->not->toBe($work->origin_run_id);
        ExecutionFixtures::setMode('acquisition', ControlMode::Paused);
        ProxymaFixtures::resetAuthority();
        DB::table('prc_source_targets')->update(['retired_at' => now()]);
        $id = new WorkId(new ProcessUuid($prepare->id));
        for ($i = 0; $i < 4 && ExecutionFixtures::work($id)->state !== 'succeeded'; $i++) {
            app(WorkFeeder::class)->tick('prepare');
            (new ExecuteWorkJob($prepare->id, ExecutionFixtures::delivery($id)->value, LedgerFixtures::uuid()->value))->handle(app(WorkRunner::class));
        }
        expect(ExecutionFixtures::work($id)->state)->toBe('succeeded')
            ->and(DB::table('prc_document_representations')->where('range_key', 'complete')->count())->toBe(1)
            ->and(DB::table('prc_operation_intents')->where('stage', 'screening')->value('pending_reason'))->toBeNull()
            ->and(DB::table('prc_process_reservations')->count())->toBe(0)
            ->and(PackageNetworkFacts::attempts()->count())->toBe(1)
            ->and(DB::table('prc_outbound_operations')->where('kind', 'legacy_bridge')->count())->toBe(0);

        // Extend the actual reference→HTTP→prepare chain into a test-only transactional sink.
        // The real screening intent is deliberately not activated or consumed by this fixture.
        $representation = DocumentRepresentation::query()->where('range_key', 'complete')->sole();
        $screening = new WorkId(LedgerFixtures::uuid());
        $analysis = AiFixtures::pinScreening($representation->id);
        app()->instance(StageRegistry::class, new StageRegistry);
        ExecutionFixtures::authority([StageCode::Screening]);
        app(StageRegistry::class)->bindHandler(stage: StageCode::Screening,
            handler: new PreparedFixtureAnalysisHandler("[page:1]\ncontent 1\n"));
        DB::statement('CREATE TEMP TABLE p36_fixture_publications(work_id uuid PRIMARY KEY,version_id bigint NOT NULL,disk text NOT NULL,object_key text NOT NULL,content_hash text NOT NULL)');
        $this->beforeApplicationDestroyed(fn () => DB::statement('DROP TABLE IF EXISTS p36_fixture_publications'));
        app(StageRegistry::class)->bindFinalization(StageCode::Screening, new FixtureStagePayloadCodec(StageCode::Screening), new PreparedFixturePublicationRepository($screening));
        app(ProcessTransaction::class)->run(fn () => app(WorkStoreRepository::class)->storeWork(new StoreWorkData(
            id: $screening,
            input: new ScreeningInput(documentVersionId: $representation->document_version_id,
                representationHash: new ContentHash($representation->content_hash), screeningRecipeHash: $analysis->recipe->hash),
            inputRevision: $analysis->expectedAnalysisGeneration,
            intentId: null,
            cohortKey: $prepare->cohort_key,
            platformId: $registry->platform_id,
            originRunId: new ProcessUuid($prepare->origin_run_id),
            priority: 100,
            eventId: LedgerFixtures::uuid(),
            projectionMessageId: LedgerFixtures::uuid(),
        )));
        app(WorkFeeder::class)->tick('analysis');
        $job = new ExecuteWorkJob(workId: $screening->value->value, dispatchGeneration: ExecutionFixtures::delivery($screening)->value,
            messageId: LedgerFixtures::uuid()->value);
        $job->handle(app(WorkRunner::class));
        $job->handle(app(WorkRunner::class));
        $publication = DB::table('p36_fixture_publications')->sole();
        expect([$publication->version_id, $publication->content_hash])->toBe([$representation->document_version_id, $representation->content_hash])
            ->and(ExecutionFixtures::work($screening)->state)->toBe('succeeded')
            ->and(PackageNetworkFacts::attempts()->count())->toBe(1)
            ->and(DB::table('prc_process_reservations')->count())->toBe(0)
            ->and(DB::table('prc_operation_intents')->where('stage', 'screening')->value('pending_reason'))->toBeNull()
            ->and(DB::table('prc_estimates')->count())->toBe(0);
    } finally {
        $wire->stop();
        PermitFixtures::clean();
    }
});

test('missing document route profile даёт configuration hold без handler fault и HTTP', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $wire = new WireFixture;
    try {
        $f = new DownloadFixtures($wire);
        $procurement = Procurement::query()->findOrFail($f->document->procurement_id);
        DB::table('prc_documents')->where('id', $f->document->id)->update(['platform_id' => $procurement->platform_id]);
        [$input, $work, $ctx] = $f->running();
        config(['outbound.routing.default_profile' => 'fixture-missing']);
        $outcome = app(RuntimeDownloadHandler::class)->execute($input, $ctx);
        expect($outcome)->toBeInstanceOf(StageBlocked::class)
            ->and($outcome->reason)->toBe(BlockedReason::PolicyReview);
        app(WorkFinalizer::class)->finish($ctx->lease(), $outcome);
        expect(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->value('fault_signature'))->toBeNull()
            ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(0)
            ->and($wire->receipts())->toBeEmpty();
    } finally {
        $wire->stop();
        PermitFixtures::clean();
    }
});

test('runtime download открывает canary probe когда target health находится в unknown', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $wire = new WireFixture;
    try {
        $registry = CallerFixtures::platform($wire);
        $routeId = DB::table('prc_network_routes')->sole()->id;
        app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->ensureProfile(app(OutboundPolicy::class)->defaultRouteProfile, $routeId));
        ProxymaCallerFixtures::install($wire);
        DB::table('pxm_target_health')->update(['state' => 'unknown', 'availability' => 'unknown']);
        DB::table('pxm_route_target_health')->update(['state' => 'unknown']);
        expect(DB::table('pxm_target_health')->pluck('state')->unique()->all())->toBe(['unknown'])
            ->and(DB::table('pxm_route_target_health')->pluck('state')->unique()->all())->toBe(['unknown']);
        app()->instance(RuntimeDownloadHandler::class, app()->makeWith(RuntimeDownloadHandler::class, ['urls' => $wire->policy()]));
        app()->forgetInstance(StageRegistry::class);
        $this->artisan('process:sync-documents')->assertSuccessful();
        $procurement = Procurement::factory()->create(['platform_id' => $registry->platform_id, 'registry_id' => $registry->id]);
        $ref = new DocumentReference(new SourceIdentity(new PlatformCode('eis'), new SourceIdentifierType($procurement->source_identity_type),
            new SourceIdentifierValue($procurement->source_identity_value)), 'fixture', 'canary.pdf', $wire->url('/document'), new SourceDocumentKey('fixture:canary', 1));
        DB::transaction(fn () => app(DocumentCatalogRepository::class)->upsertReference($procurement, $ref, ContentHash::of('access')));
        $wire->respond('/document', "%PDF-1.4\ncanary\n%%EOF", headers: ['Content-Type' => 'application/pdf']);
        expect(app(DocumentFeeder::class)->reconcile())->toBe(1);
        $work = DB::table('prc_process_work')->where('stage', 'download')->sole();
        expect(app(WorkFeeder::class)->tick('download'))->toBe(1);
        (new ExecuteWorkJob($work->id, 1, LedgerFixtures::uuid()->value))->handle(app(WorkRunner::class));
        expect(DB::table('prc_process_work')->where('id', $work->id)->value('state'))->toBe('succeeded')
            ->and(DB::table('prc_outbound_operations')->where('caller_code', 'document.download_canary')->value('state'))->toBe('closed')
            ->and(DB::table('pxm_target_health')->whereIn('state', ['closed'])->count())->toBe(1);
    } finally {
        $wire->stop();
        PermitFixtures::clean();
    }
});

test('runtime download использует имя файла и расширение из заголовка Content-Disposition', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $wire = new WireFixture;
    try {
        $registry = CallerFixtures::platform($wire);
        $routeId = DB::table('prc_network_routes')->sole()->id;
        app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->ensureProfile(app(OutboundPolicy::class)->defaultRouteProfile, $routeId));
        ProxymaCallerFixtures::install($wire);
        app()->instance(RuntimeDownloadHandler::class, app()->makeWith(RuntimeDownloadHandler::class, ['urls' => $wire->policy()]));
        app()->forgetInstance(StageRegistry::class);
        $this->artisan('process:sync-documents')->assertSuccessful();
        $procurement = Procurement::factory()->create(['platform_id' => $registry->platform_id, 'registry_id' => $registry->id]);
        $ref = new DocumentReference(new SourceIdentity(new PlatformCode('eis'), new SourceIdentifierType($procurement->source_identity_type),
            new SourceIdentifierValue($procurement->source_identity_value)), 'fixture',
            'Электронный документ, полученный из внешней системы.pdf', $wire->url('/document'), new SourceDocumentKey('fixture:external-doc', 1));
        DB::transaction(fn () => app(DocumentCatalogRepository::class)->upsertReference($procurement, $ref, ContentHash::of('access')));
        $oleDoc = "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1".str_repeat("\0", 512);
        $encodedName = rawurlencode('Электронный документ, полученный из внешней системы.doc');
        $wire->respond('/document', $oleDoc, headers: [
            'Content-Type' => 'application/download',
            'Content-Disposition' => "attachment; filename=\"{$encodedName}\"",
        ]);
        expect(app(DocumentFeeder::class)->reconcile())->toBe(1);
        $work = DB::table('prc_process_work')->where('stage', 'download')->sole();
        expect(app(WorkFeeder::class)->tick('download'))->toBe(1);
        (new ExecuteWorkJob($work->id, 1, LedgerFixtures::uuid()->value))->handle(app(WorkRunner::class));
        $version = DB::table('prc_document_versions')->sole();
        expect(DB::table('prc_process_work')->where('id', $work->id)->value('state'))->toBe('succeeded')
            ->and($version->filename)->toBe('Электронный документ, полученный из внешней системы.doc')
            ->and($version->extension)->toBe('doc');
    } finally {
        $wire->stop();
        PermitFixtures::clean();
    }
});
