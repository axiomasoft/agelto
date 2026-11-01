<?php

declare(strict_types=1);
use App\Dto\Procurement\Document\PreparedDocument;
use App\Dto\Procurement\Document\PrepareOcrCheckpoint;
use App\Dto\Procurement\Process\CheckpointResult;
use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\LeaseRenewal;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\WorkState;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Exceptions\Procurement\Prepare\ToolUnavailable;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Repositories\Procurement\Document\PrepareStorageRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Document\ArtifactGarbageCollector;
use App\Services\Procurement\Document\PrepareHandler;
use App\Services\Procurement\Document\PrepareStageCodec;
use App\Services\Procurement\Document\PrepareWorkspace;
use App\Services\Procurement\Process\Contracts\LeaseKeeper;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PrepareFixtures;
use Tests\Support\ProcessCore\StubPdfTools;

pest()->group('t26', 't10', 'p33');
beforeEach(function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
});

test('batch checkpoints переживают restart без повторения committed ranges и сохраняют native OCR anchors', function (): void {
    $f = new PrepareFixtures;
    $tools = new StubPdfTools;
    $deliveries = 0;
    do {
        $ctx = $f->context();
        $outcome = $f->handler($tools)->execute($f->input, $ctx);
        $deliveries++;
        expect(app(WorkFinalizer::class)->finish($ctx->lease(), $outcome)->status)->toBe(CompletionStatus::Applied);
        // Each delivery reconstructs codec/handler and lease from durable rows.
    } while ($outcome instanceof StagePartial && $deliveries < 10);
    expect($deliveries)->toBeGreaterThanOrEqual(3)->and($outcome)->toBeInstanceOf(StageSucceeded::class)
        ->and($tools->calls)->toBe([[0, 5], [5, 25], [25, 45], [45, 46]])
        ->and(DB::table('prc_process_work')->where('id', $f->work->value->value)->value('state'))->toBe('succeeded')
        ->and(DB::table('prc_document_representations')->where('range_key', 'complete')->count())->toBe(1);
    $rep = DB::table('prc_document_representations')->where('range_key', 'complete')->first();
    $expected = '';
    for ($p = 1; $p <= 46; $p++) {
        $expected .= '[page:'.$p."]\ncontent ".$p."\n";
    }
    expect(Storage::disk($rep->disk)->get($rep->storage_path))->toBe($expected)
        ->and(DB::table('prc_storage_reservations')->where('phase', 'prepare')->whereIn('status', ['writing', 'reserved', 'staged', 'cleaning'])->count())->toBe(0);
});

test('multi page failure split и singleton budget сохраняются между deliveries и не дают complete', function (int $failingPage): void {
    $f = new PrepareFixtures;
    $tools = new StubPdfTools(6, $failingPage);
    $deliveries = 0;
    $lastManifest = null;
    do {
        $ctx = $f->context();
        $outcome = $f->handler($tools)->execute($f->input, $ctx);
        $deliveries++;
        if ($outcome instanceof StagePartial) {
            $lastManifest = $outcome->checkpoint;
        }
        app(WorkFinalizer::class)->finish($ctx->lease(), $outcome);
    } while ($outcome instanceof StagePartial && $deliveries < 20);
    expect($outcome)->toBeInstanceOf(StageBlocked::class)->and($outcome->reason)->toBe(BlockedReason::DocumentQuality)
        ->and(array_count_values(array_map(fn ($r) => implode(':', $r), $tools->calls))[($failingPage - 1).':'.$failingPage])->toBe(2)
        ->and($lastManifest->failed[0]->evidence)->toBe('page_timeout')
        ->and($lastManifest->coverage()->complete())->toBeFalse()
        ->and(DB::table('prc_document_representations')->where('range_key', 'complete')->count())->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $f->work->value->value)->value('item_failures'))->toBe(0);
})->with([4, 6]);

test('truncation301 и exit0 unrecognized не превращаются в complete даже ниже 10 процентов', function (int $count, array $skip): void {
    $f = new PrepareFixtures;
    $tools = new StubPdfTools($count, null, $skip);
    $n = 0;
    do {
        $ctx = $f->context();
        $outcome = $f->handler($tools)->execute($f->input, $ctx);
        $n++;
        if ($outcome instanceof StagePartial) {
            $manifest = $outcome->checkpoint;
        }
        app(WorkFinalizer::class)->finish($ctx->lease(), $outcome);
    } while ($outcome instanceof StagePartial && $n < 30);
    expect($outcome)->toBeInstanceOf(StageBlocked::class)->and($outcome->reason)->toBe(BlockedReason::DocumentQuality)
        ->and($manifest->coverage()->complete())->toBeFalse()->and($manifest->truncated())->toBe($count === 301)
        ->and($manifest->qualityLabel(.1))->toBe($skip === [] ? 'complete' : 'partial')
        ->and(DB::table('prc_document_representations')->where('range_key', 'complete')->count())->toBe(0);
})->with([[301, []], [20, [20]]]);

test('recipe mismatch блокирует до нового tool effect и checkpoint codec отвергает coercion extras unknown schema', function (): void {
    $f = new PrepareFixtures;
    $tools = new StubPdfTools;
    $ctx = $f->context();
    $out = $f->handler($tools)->execute($f->input, $ctx);
    app(WorkFinalizer::class)->finish($ctx->lease(), $out);
    config()->set('documents.prepare.languages', 'eng');
    $ctx = $f->context();
    $out = $f->handler($tools)->execute($f->input, $ctx);
    expect($out)->toBeInstanceOf(StageBlocked::class)->and($out->reason)->toBe(BlockedReason::PreparationRecipeMismatch)->and($tools->calls)->toHaveCount(1);
    $codec = new PrepareStageCodec;
    $good = $codec->encodeCheckpoint($ctx->lease()->checkpoint->stageProgress);
    expect($codec->decodeCheckpoint($good))->toEqual($ctx->lease()->checkpoint->stageProgress);
    $data = json_decode($good->json, true);
    $data['next'] = '5';
    expect(fn () => $codec->decodeCheckpoint(new VersionedPayload(json_encode($data), 1)))->toThrow(InvalidArgumentException::class);
    $data['next'] = 5;
    $data['extra'] = 1;
    expect(fn () => $codec->decodeCheckpoint(new VersionedPayload(json_encode($data), 1)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $codec->decodeCheckpoint(new VersionedPayload($good->json, 2)))->toThrow(InvalidArgumentException::class);
});

test('cancel после batch checkpoint запрещает старую финализацию и не создаёт representation', function (): void {
    $f = new PrepareFixtures;
    $ctx = $f->context();
    $tools = new StubPdfTools(3);
    $out = $f->handler($tools)->execute($f->input, $ctx);
    $batchReservation = $out->checkpoint->batches[0]->artifact->reservation;
    expect(app(ArtifactGarbageCollector::class)->cleanup($batchReservation))->toBeFalse();
    $stale = $ctx->lease();
    expect(FinalizeFixtures::revoke($f->work, cancel: true))->toBeTrue();
    expect(app(WorkFinalizer::class)->finish($stale, $out)->status)->toBe(CompletionStatus::LostOwnership)
        ->and(DB::table('prc_document_representations')->count())->toBe(0)
        ->and(app(ArtifactGarbageCollector::class)->cleanup($batchReservation))->toBeTrue();
});

test('tmp остаётся charged до подтверждённого stop и cleanup retry идемпотентен', function (): void {
    $f = new PrepareFixtures;
    $ctx = $f->context();
    $reservations = app(PrepareStorageRepository::class)->reserve($ctx->lease());
    $workspace = app(PrepareWorkspace::class);
    $r = $reservations->workspace;
    $dir = $workspace->open($r);
    file_put_contents($dir.'/tool-fixture.active', 'running');
    expect($workspace->finish($r))->toBeFalse()
        ->and(DB::table('prc_storage_reservations')->where('id', $r->id->value)->value('status'))->toBe('writing')
        ->and(DB::table('prc_storage_reservations')->where('id', $r->id->value)->value('cleanup_verified_at'))->toBeNull();
    file_put_contents($dir.'/tool-fixture', json_encode(['stopped' => true]));
    unlink($dir.'/tool-fixture.active');
    expect($workspace->cleanup($r))->toBeTrue()->and($workspace->cleanup($r))->toBeTrue()
        ->and(DB::table('prc_storage_reservations')->where('id', $r->id->value)->value('status'))->toBe('cleaned')
        ->and(DB::table('prc_storage_reservations')->where('id', $r->id->value)->value('cleanup_verified_at'))->not->toBeNull();
});

test('смена document head между prepare result и finalize запрещает старую complete publication', function (): void {
    $f = new PrepareFixtures;
    $tools = new StubPdfTools(2);
    $ctx = $f->context();
    $partial = $f->handler($tools)->execute($f->input, $ctx);
    app(WorkFinalizer::class)->finish($ctx->lease(), $partial);
    $ctx = $f->context();
    $out = $f->handler($tools)->execute($f->input, $ctx);
    expect($out)->toBeInstanceOf(StageSucceeded::class);
    $head = $out->result->head;
    DocumentStorageFixtures::observe($head, DocumentStorageFixtures::upload('new source revision', DocumentStorageFixtures::domain()));
    expect(app(WorkFinalizer::class)->finish($ctx->lease(), $out)->state)->toBe(WorkState::Superseded)
        ->and(DB::table('prc_document_representations')->count())->toBe(0);
});

test('cancel между verified object put и checkpoint CAS оставляет orphan charged без accepted batch', function (): void {
    $f = new PrepareFixtures;
    $keeper = new class($f) implements LeaseKeeper
    {
        private int $checkpoints = 0;

        public function __construct(private PrepareFixtures $fixture) {}

        public function heartbeat(ExecutionLease $lease): LeaseRenewal
        {
            return app(WorkStoreRepository::class)->heartbeat($lease);
        }

        public function checkpoint(ExecutionLease $lease, StageCheckpoint $checkpoint, int $expectedSeq): CheckpointResult
        {
            if (++$this->checkpoints === 2) {
                FinalizeFixtures::revoke($this->fixture->work, cancel: true);
            }

            return app(WorkStoreRepository::class)->checkpoint($lease, $checkpoint, $expectedSeq);
        }
    };
    $ctx = $f->context($keeper);
    expect(fn () => $f->handler(new StubPdfTools(2))->execute($f->input, $ctx))->toThrow(LeaseLost::class);
    expect(DB::table('prc_process_work')->where('id', $f->work->value->value)->value('checkpoint'))->toBeNull()
        ->and(DB::table('prc_document_representations')->count())->toBe(0)
        ->and(DB::table('prc_storage_reservations')->where('phase', 'prepare')->where('status', 'staged')->count())->toBe(1)
        ->and(DB::table('prc_storage_reservations')->where('phase', 'prepare')->where('status', 'writing')->count())->toBe(0);
});

test('израсходованный setup budget блокирует несовместимый converter profile без page failures и пустого Partial', function (): void {
    $f = new PrepareFixtures;
    $ctx = $f->context();
    $clock = new class(app(ProcessClock::class)) implements ProcessClock
    {
        public int $offset = 0;

        public function __construct(private ProcessClock $real) {}

        public function dbNow(): DbInstant
        {
            return $this->real->dbNow();
        }

        public function monotonicNs(): int
        {
            return hrtime(true) + $this->offset;
        }

        public function sleep(Duration $duration): void
        {
            $this->offset += $duration->nanos();
        }
    };
    $tools = new StubPdfTools(1, afterSetup: fn () => $clock->offset += 201_000_000_000);
    $handler = app()->makeWith(PrepareHandler::class, ['tools' => $tools, 'clock' => $clock]);
    $out = $handler->execute($f->input, $ctx);
    expect($out)->toBeInstanceOf(StageBlocked::class)->and($out->reason)->toBe(BlockedReason::ConverterPolicyIncompatible)
        ->and($tools->calls)->toBe([]);
    app(WorkFinalizer::class)->finish($ctx->lease(), $out);
    expect(DB::table('prc_process_work')->where('id', $f->work->value->value)->value('item_failures'))->toBe(0);
});

test('storage и converter interruptions имеют точный dependency reason и не тратят page или item budget', function (string $dependency): void {
    $f = new PrepareFixtures;
    $ctx = $f->context();
    $tools = new StubPdfTools(1, afterSetup: static function () use ($dependency): void {
        if ($dependency === 'storage') {
            throw new ArtifactUnavailable('fixture');
        }
        throw new ToolUnavailable('fixture');
    });
    $out = $f->handler($tools)->execute($f->input, $ctx);
    expect($out)->toBeInstanceOf(StageDeferred::class)
        ->and($out->reason)->toBe($dependency === 'storage' ? DeferralReason::StorageUnavailable : DeferralReason::ConverterUnavailable)
        ->and($tools->calls)->toBe([]);
    app(WorkFinalizer::class)->finish($ctx->lease(), $out);
    expect(DB::table('prc_process_work')->where('id', $f->work->value->value)->value('item_failures'))->toBe(0);
})->with(['storage', 'converter']);

test('finalizer отвергает чужой source hash до transfer publication и work transition', function (): void {
    $f = new PrepareFixtures;
    $tools = new StubPdfTools(2);
    $ctx = $f->context();
    $partial = $f->handler($tools)->execute($f->input, $ctx);
    app(WorkFinalizer::class)->finish($ctx->lease(), $partial);
    $ctx = $f->context();
    $out = $f->handler($tools)->execute($f->input, $ctx);
    $result = $out->result;
    $m = $result->manifest;
    $wrong = new PrepareOcrCheckpoint(LedgerFixtures::hash('foreign-source'), $m->recipeHash, $m->originalPages, $m->pageLimit, $m->pending, $m->batches, $m->failed, $m->nextBatchSize);
    $forged = new StageSucceeded(new PreparedDocument($result->head, $wrong, $result->text));
    expect(fn () => app(WorkFinalizer::class)->finish($ctx->lease(), $forged))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('prc_document_representations')->count())->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $f->work->value->value)->value('state'))->toBe('running');
});
