<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\StageDeferred;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\ProcurementEvent;
use App\Repositories\Procurement\Document\DocumentVersionRepository;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\DownloadFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;

pest()->group('document-processing');
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

test('download first same replaced bytes публикует файл immutable версии и ровно соответствующие события', function (): void {
    $f = new DownloadFixtures($this->wire);
    foreach (['first', 'first', 'second'] as $bytes) {
        $bytes = "%PDF-1.4\n".$bytes;
        $this->wire->respond('/document', $bytes, headers: ['Content-Type' => 'application/pdf']);
        [$input, $work, $ctx] = $f->running(app(DocumentVersionRepository::class)->head($f->document->id));
        app(WorkFinalizer::class)->finish($ctx->lease(), $f->handler()->execute($input, $ctx));
        $version = DB::table('prc_document_versions')->where('document_id', $f->document->id)->orderByDesc('revision_no')->first();
        expect(Storage::disk($version->disk)->get($version->storage_path))->toBe($bytes);
    }
    expect(DB::table('prc_document_versions')->count())->toBe(2)
        ->and(ProcurementEvent::query()->where('procurement_id', $f->document->procurement_id)->where('event_type', ProcurementEventType::DocumentAdded)->count())->toBe(1)
        ->and(ProcurementEvent::query()->where('procurement_id', $f->document->procurement_id)->where('event_type', ProcurementEventType::DocumentReplaced)->count())->toBe(1);
});

test('download storage false write или exception не публикует версию событие и не удаляет previous canonical object', function (bool $throws): void {
    $f = new DownloadFixtures($this->wire);
    $this->wire->respond('/document', "%PDF-1.4\nfirst", headers: ['Content-Type' => 'application/pdf']);
    [$input, $work, $ctx] = $f->running();
    app(WorkFinalizer::class)->finish($ctx->lease(), $f->handler()->execute($input, $ctx));
    $old = DB::table('prc_document_versions')->sole();
    $disk = Storage::disk('local');
    $mock = Mockery::mock($disk)->makePartial();
    $failure = $mock->shouldReceive('writeStream');
    if ($throws) {
        $failure->andThrow(UnableToWriteFile::atLocation('staging', 'fixture outage'));
    } else {
        $failure->andReturnFalse();
    }
    Storage::partialMock()->shouldReceive('disk')->with('local')->andReturn($mock);
    $this->wire->respond('/document', "%PDF-1.4\nsecond", headers: ['Content-Type' => 'application/pdf']);
    [$input, $work, $ctx] = $f->running(app(DocumentVersionRepository::class)->head($f->document->id));
    $outcome = $f->handler()->execute($input, $ctx);
    expect($outcome)->toBeInstanceOf(StageDeferred::class);
    app(WorkFinalizer::class)->finish($ctx->lease(), $outcome);
    expect(DB::table('prc_document_versions')->count())->toBe(1)
        ->and(ProcurementEvent::query()->where('procurement_id', $f->document->procurement_id)->count())->toBe(1)
        ->and($disk->get($old->storage_path))->toBe("%PDF-1.4\nfirst")
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(0);
})->with([false, true]);
