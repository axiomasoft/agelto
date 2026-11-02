<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\DocumentReference;
use App\Dto\Procurement\Document\SourceDocumentKey;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\SourceIdentifierType;
use App\Dto\Procurement\Platform\SourceIdentifierValue;
use App\Dto\Procurement\Platform\SourceIdentity;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\StageCode;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Document\DocumentCatalogRepository;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Services\Procurement\Document\DocumentFeeder;
use App\Services\Procurement\Document\DocumentRuntime;
use App\Services\Procurement\Document\RuntimeDownloadHandler;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Prepare\PdfPreparationTools;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\DownloadFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\Http\CallerFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PrepareFixtures;
use Tests\Support\ProcessCore\StubPdfTools;

pest()->group('t10', 't11', 'p35');

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
        DB::table('prc_source_targets')->update(['retired_at' => now()]);
        $id = new WorkId(new ProcessUuid($prepare->id));
        for ($i = 0; $i < 4 && ExecutionFixtures::work($id)->state !== 'succeeded'; $i++) {
            app(WorkFeeder::class)->tick('prepare');
            (new ExecuteWorkJob($prepare->id, ExecutionFixtures::delivery($id)->value, LedgerFixtures::uuid()->value))->handle(app(WorkRunner::class));
        }
        expect(ExecutionFixtures::work($id)->state)->toBe('succeeded')
            ->and(DB::table('prc_document_representations')->where('range_key', 'complete')->count())->toBe(1)
            ->and(DB::table('prc_operation_intents')->where('stage', 'screening')->value('pending_reason'))->toBe('handler_not_activated')
            ->and(DB::table('prc_process_reservations')->count())->toBe(0)
            ->and(DB::table('prc_request_attempts')->count())->toBe(1)
            ->and(DB::table('prc_outbound_operations')->where('kind', 'legacy_bridge')->count())->toBe(0);
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
