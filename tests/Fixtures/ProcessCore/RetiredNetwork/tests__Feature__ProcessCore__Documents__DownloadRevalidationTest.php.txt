<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\DocumentVersionRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Document\ArtifactGarbageCollector;
use App\Services\Procurement\Document\DocumentRevalidator;
use App\Services\Procurement\Document\DownloadStageCodec;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\DownloadFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\Proxy\HealthFixtures;

pest()->group('transport-and-work-budget', 'document-processing', 'document-storage-and-references');

beforeEach(function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $this->wire = new WireFixture;
});
afterEach(function (): void {
    $this->wire->stop();
    PermitFixtures::clean();
});

const P32_PDF = "%PDF-1.4\n1 0 obj <</Type /Catalog>> endobj\n%%EOF\n";

test('два connection failures и success дают три физических request и ноль item failures', function (): void {
    // /sequence drops TCP twice; its ordinal is exclusively server-owned.
    $f = new DownloadFixtures($this->wire, '/sequence');
    [$input, $work, $context] = $f->running();
    $outcome = $f->handler()->execute($input, $context);
    expect($outcome)->toBeInstanceOf(StageSucceeded::class);
    $result = app(WorkFinalizer::class)->finish($context->lease(), $outcome);
    expect($result->state)->toBe(WorkState::Succeeded)
        ->and(DB::table('prc_request_attempts')->count())->toBe(3)
        ->and(DB::table('prc_request_attempts')->where('outcome', 'response')->count())->toBe(1)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(0)
        ->and($this->wire->receipts())->toHaveCount(3)
        ->and(DB::table('prc_document_versions')->count())->toBe(1)
        ->and(DB::table('prc_storage_reservations')->where('status', 'transferred')->count())->toBe(1);
});

test('truncated response и исчерпание retries откладывают work без версии и item failures', function (string $path): void {
    $f = new DownloadFixtures($this->wire, $path);
    [$input, $work, $context] = $f->running();
    $outcome = $f->handler()->execute($input, $context);
    expect($outcome)->toBeInstanceOf(StageDeferred::class);
    expect(app(WorkFinalizer::class)->finish($context->lease(), $outcome)->state)->toBe(WorkState::Deferred)
        ->and(DB::table('prc_request_attempts')->count())->toBe(3)
        ->and(DB::table('prc_document_versions')->count())->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(0)
        ->and(DB::table('prc_storage_reservations')->whereNotIn('status', ['cleaned'])->count())->toBe(0);
})->with(['/truncated', '/drop']);

test('HTML под PDF размер и checksum не допускают публикацию', function (string $case): void {
    $this->wire->respond('/document', $case === 'html' ? '<html><form>login</form></html>' : P32_PDF,
        headers: ['Content-Type' => 'application/pdf']);
    $f = new DownloadFixtures($this->wire, expectedHash: $case === 'checksum' ? ContentHash::of('wrong') : null);
    if ($case === 'size') {
        $this->wire->respond('/document', str_repeat('x', 65537));
        $f->path = '/oversized';
        $this->wire->respond('/oversized', str_repeat('x', 65537));
    }
    [$input, $work, $context] = $f->running();
    $outcome = $f->handler()->execute($input, $context);
    expect($outcome instanceof StageBlocked || $outcome instanceof StageDeferred)->toBeTrue();
    app(WorkFinalizer::class)->finish($context->lease(), $outcome);
    expect(DB::table('prc_document_versions')->count())->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(0);
})->with(['html', 'checksum', 'size']);

test('same bytes затем A B A сохраняют revisions blobs validators и новый due cycle', function (): void {
    $f = new DownloadFixtures($this->wire);
    foreach ([P32_PDF, P32_PDF, P32_PDF.'B', P32_PDF] as $index => $bytes) {
        $this->wire->respond('/document', $bytes, headers: ['ETag' => '"v'.$index.'"', 'Last-Modified' => 'Sun, 01 Nov 2026 12:00:00 GMT']);
        $head = app(DocumentVersionRepository::class)->head($f->document->id);
        $intent = null;
        if ($index > 0) {
            DB::table('prc_documents')->where('id', $f->document->id)->update(['next_revalidation_due_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
            $intent = app(DocumentRevalidator::class)->allocate($f->document->id);
            expect($intent)->not->toBeNull()->and(app(DocumentRevalidator::class)->allocate($f->document->id))->toBeNull();
        }
        [$input, $work, $context] = $f->running($head, $intent);
        $outcome = $f->handler()->execute($input, $context);
        expect($outcome)->toBeInstanceOf(StageSucceeded::class);
        $codec = new DownloadStageCodec;
        expect($codec->decodeResult($codec->encodeResult($outcome->result)))->toEqual($outcome->result);
        expect(app(WorkFinalizer::class)->finish($context->lease(), $outcome)->state)->toBe(WorkState::Succeeded);
    }
    expect(DB::table('prc_document_versions')->count())->toBe(3)
        ->and(DB::table('prc_document_blobs')->count())->toBe(2)
        ->and(DB::table('prc_operation_intents')->where('state', 'terminal')->count())->toBe(4)
        ->and(DB::table('prc_documents')->where('id', $f->document->id)->value('acquisition_cycle_seq'))->toBe(4)
        ->and(DB::table('prc_document_versions')->orderByDesc('id')->value('etag'))->toBe('"v3"');
});

test('смена head и takeover запрещают старую публикацию а unrecovered expiry разрешает DB commit', function (string $change): void {
    $this->wire->respond('/document', P32_PDF);
    $f = new DownloadFixtures($this->wire);
    [$input, $work, $context] = $f->running();
    $outcome = $f->handler()->execute($input, $context);
    expect($outcome)->toBeInstanceOf(StageSucceeded::class);
    match ($change) {
        'head' => DB::table('prc_documents')->where('id', $f->document->id)->increment('head_generation'),
        'takeover' => FinalizeFixtures::revoke($work, true),
        'expiry' => FinalizeFixtures::expire($work),
    };
    $result = app(WorkFinalizer::class)->finish($context->lease(), $outcome);
    expect($result->status)->toBe($change === 'takeover' ? CompletionStatus::LostOwnership : CompletionStatus::Applied)
        ->and(DB::table('prc_document_versions')->count())->toBe($change === 'expiry' ? 1 : 0);
})->with(['head', 'takeover', 'expiry']);

test('source outage после bytes не отменяет готовый result и immutable storage', function (): void {
    $this->wire->respond('/document', P32_PDF);
    $f = new DownloadFixtures($this->wire);
    [$input, $work, $context] = $f->running();
    $outcome = $f->handler()->execute($input, $context);
    $this->wire->stop();
    DB::table('prc_source_targets')->update(['retired_at' => DB::raw('clock_timestamp()')]);
    DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->update(['desired_mode' => 'paused']);
    expect(app(WorkFinalizer::class)->finish($context->lease(), $outcome)->state)->toBe(WorkState::Succeeded);
    $version = DB::table('prc_document_versions')->sole();
    expect(Storage::disk($version->disk)->get($version->storage_path))->toBe(P32_PDF);
});

test('signed reference обновляется один раз и повторный access failure не зацикливается', function (bool $success): void {
    $this->wire->respond('/document', 'expired', 403);
    $this->wire->respond('/refreshed', $success ? P32_PDF : 'expired again', $success ? 200 : 403);
    $f = new DownloadFixtures($this->wire);
    [$input, $work, $context] = $f->running();
    $outcome = $f->handler()->execute($input, $context);
    expect($outcome)->toBeInstanceOf($success ? StageSucceeded::class : StageBlocked::class)
        ->and($f->access->refreshes)->toBe(1)
        ->and($this->wire->receipts())->toHaveCount(2)
        ->and(DB::table('prc_request_attempts')->count())->toBe(2);
    app(WorkFinalizer::class)->finish($context->lease(), $outcome);
    expect(DB::table('prc_document_versions')->count())->toBe($success ? 1 : 0);
})->with([true, false]);

test('две capacity reservations rollback вместе и отсутствие места запрещает HTTP', function (): void {
    $f = new DownloadFixtures($this->wire);
    DB::table('prc_storage_domains')->where('domain_key', config('documents.download.temporary_domain'))->update(['capacity_bytes' => 100, 'high_watermark_bytes' => 90]);
    [$input, $work, $context] = $f->running();
    $outcome = $f->handler()->execute($input, $context);
    expect($outcome)->toBeInstanceOf(StageDeferred::class)
        ->and($this->wire->receipts())->toHaveCount(0)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and(DB::table('prc_storage_reservations')->count())->toBe(0);
});

test('истёкший lease запрещает reservation и HTTP даже до recovery', function (): void {
    $f = new DownloadFixtures($this->wire);
    [$input, $work, $context] = $f->running();
    FinalizeFixtures::expire($work);
    expect(fn () => $f->handler()->execute($input, $context))->toThrow(LeaseLost::class);
    expect($this->wire->receipts())->toHaveCount(0)
        ->and(DB::table('prc_storage_reservations')->count())->toBe(0);
});

test('journal failure откатывает version head intent storage transfer и work вместе', function (): void {
    $this->wire->respond('/document', P32_PDF);
    $f = new DownloadFixtures($this->wire);
    [$input, $work, $context] = $f->running();
    $outcome = $f->handler()->execute($input, $context);
    DB::unprepared("CREATE FUNCTION p32_journal_reject() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.entity_kind = 'work' AND NEW.new_state = 'succeeded' THEN RAISE EXCEPTION 'p32 journal failure'; END IF; RETURN NEW; END \$\$");
    DB::unprepared('CREATE TRIGGER p32_journal_reject BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION p32_journal_reject()');
    try {
        expect(fn () => app(WorkFinalizer::class)->finish($context->lease(), $outcome))->toThrow(QueryException::class, 'p32 journal failure');
        expect(DB::table('prc_document_versions')->count())->toBe(0)
            ->and(DB::table('prc_document_blobs')->count())->toBe(0)
            ->and(DB::table('prc_storage_domains')->sum('accounted_durable_bytes'))->toBe(0)
            ->and(DB::table('prc_operation_intents')->where('id', $input->acquisitionIntentId->value)->value('state'))->toBe('active')
            ->and(DB::table('prc_documents')->where('id', $f->document->id)->value('head_generation'))->toBe(1)
            ->and(ExecutionFixtures::work($work)->state)->toBe('running');
    } finally {
        DB::unprepared('DROP TRIGGER p32_journal_reject ON prc_process_transitions');
        DB::unprepared('DROP FUNCTION p32_journal_reject()');
    }
    expect(app(WorkFinalizer::class)->finish($context->lease(), $outcome)->state)->toBe(WorkState::Succeeded);
});

test('два scheduler процесса выделяют один due intent без повторения cycle', function (): void {
    $this->wire->respond('/document', P32_PDF);
    $f = new DownloadFixtures($this->wire);
    [$input, $work, $context] = $f->running();
    app(WorkFinalizer::class)->finish($context->lease(), $f->handler()->execute($input, $context));
    DB::table('prc_documents')->where('id', $f->document->id)->update(['next_revalidation_due_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
    $code = '$id = app(\\App\\Services\\Procurement\\Document\\DocumentRevalidator::class)->allocate('.$f->document->id.'); return ["intent"=>$id?->value,"pid"=>\\Illuminate\\Support\\Facades\\DB::selectOne("SELECT pg_backend_pid() pid")->pid];';
    $results = ConcurrentTransactions::concurrently(['a' => $code, 'b' => $code]);
    expect(array_filter(array_column($results, 'intent')))->toHaveCount(1)
        ->and($results['a']['pid'])->not->toBe($results['b']['pid'])
        ->and(DB::table('prc_operation_intents')->where('state', 'pending')->count())->toBe(1)
        ->and(DB::table('prc_documents')->where('id', $f->document->id)->value('acquisition_cycle_seq'))->toBe(2);
});

test('неработающий proxy меняется на разрешённый proxy а одиночный плохой route bounded без direct fallback', function (bool $alternate): void {
    $this->wire->respond('/document', P32_PDF);
    $f = new DownloadFixtures($this->wire);
    $routes = new HealthFixtures($this->wire, $f->network);
    $bad = $routes->proxy(1);
    $bindings = [$bad];
    if ($alternate) {
        $bindings[] = $routes->proxy();
    }
    [$input, $work, $context] = $f->running();
    $outcome = $f->handler($routes->gate($bindings))->execute($input, $context);
    expect($outcome)->toBeInstanceOf($alternate ? StageSucceeded::class : StageDeferred::class);
    app(WorkFinalizer::class)->finish($context->lease(), $outcome);
    $requests = DB::table('prc_request_attempts')->orderBy('request_seq')->get();
    expect($requests)->toHaveCount($alternate ? 2 : 3)
        ->and($requests->first()->route_id)->toBe($bad->id->value)
        ->and($requests->pluck('route_id')->unique()->count())->toBe($alternate ? 2 : 1)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(0)
        ->and(DB::table('prc_document_versions')->count())->toBe($alternate ? 1 : 0);
})->with([true, false]);

test('pause и handler hold до storage reservation сразу дают typed blocked без HTTP', function (bool $pause): void {
    $f = new DownloadFixtures($this->wire);
    [$input, $work, $context] = $f->running();
    if ($pause) {
        ExecutionFixtures::setMode('documents', ControlMode::Paused);
    } else {
        ExecutionFixtures::holdHandler(StageCode::Download);
    }
    $outcome = $f->handler()->execute($input, $context);
    expect($outcome)->toBeInstanceOf(StageBlocked::class)
        ->and($outcome->reason)->toBe($pause ? BlockedReason::Paused : BlockedReason::HandlerHold)
        ->and($this->wire->receipts())->toHaveCount(0)
        ->and(DB::table('prc_storage_reservations')->count())->toBe(0);
    expect(app(WorkFinalizer::class)->finish($context->lease(), $outcome)->state)->toBe(WorkState::Blocked)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(0);
})->with([true, false]);

test('GC mark-before-delete во время finalize переводит work в deferred storage_unavailable без item failure до завершения удаления', function (): void {
    $this->wire->respond('/document', P32_PDF);
    $f = new DownloadFixtures($this->wire);
    $domain = DB::table('prc_storage_domains')->where('domain_key', config('documents.download.durable_domain'))->value('id');
    $orphan = DocumentStorageFixtures::upload(P32_PDF, new ProcessUuid($domain));
    $blob = app(ProcessTransaction::class)->run(function () use ($orphan) {
        app(StorageCapacityRepository::class)->lockDomains([$orphan->reservation->domainId]);

        return app(ArtifactRepository::class)->acquireLocked($orphan);
    });
    DB::update("UPDATE prc_document_blobs SET verified_at=clock_timestamp()-interval '1 hour' WHERE id=?", [$blob->id->value]);
    $ticket = app(ArtifactRepository::class)->markDeletion($blob, 900);
    expect($ticket)->not->toBeNull();
    [$input, $work, $context] = $f->running();
    $outcome = $f->handler()->execute($input, $context);
    expect($outcome)->toBeInstanceOf(StageSucceeded::class);
    $deferred = app(WorkFinalizer::class)->finish($context->lease(), $outcome);
    expect($deferred->state)->toBe(WorkState::Deferred)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('reason_code'))->toBe('storage_unavailable')
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(0)
        ->and(DB::table('prc_document_versions')->count())->toBe(0);
    expect(app(ArtifactGarbageCollector::class)->deleteMarked($ticket))->toBeTrue();
    $retryLease = FinalizeFixtures::rerun($work);
    $retryContext = new ExecutionContext($retryLease, app(WorkStoreRepository::class), app(ProcessClock::class),
        Duration::seconds(300), Duration::seconds(420), Duration::seconds(15), Duration::seconds(15), Duration::seconds(30));
    expect(app(WorkFinalizer::class)->finish($retryLease, $f->handler()->execute($input, $retryContext))->state)->toBe(WorkState::Succeeded)
        ->and(DB::table('prc_document_versions')->count())->toBe(1);
});
