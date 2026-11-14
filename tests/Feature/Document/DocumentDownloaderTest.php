<?php

declare(strict_types=1);

use App\Repositories\Procurement\Document\DocumentVersionRepository;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\DownloadFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\PackageNetworkFacts;

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

test('новый downloader восстанавливает missing corrupt object без новой source revision', function (bool $corrupt): void {
    $bytes = "%PDF-1.4\nverified same bytes\n%%EOF";
    $this->wire->respond('/document', $bytes, headers: ['Content-Type' => 'application/pdf']);
    $f = new DownloadFixtures($this->wire);
    [$input, $work, $context] = $f->running();
    app(WorkFinalizer::class)->finish($context->lease(), $f->handler()->execute($input, $context));
    $version = DB::table('prc_document_versions')->sole();
    if ($corrupt) {
        Storage::disk($version->disk)->put($version->storage_path, 'corrupt');
    } else {
        Storage::disk($version->disk)->delete($version->storage_path);
    }
    $head = app(DocumentVersionRepository::class)->head($f->document->id);
    [$input, $work, $context] = $f->running($head);
    app(WorkFinalizer::class)->finish($context->lease(), $f->handler()->execute($input, $context));
    expect(DB::table('prc_document_versions')->count())->toBe(1)
        ->and(DB::table('prc_document_blobs')->count())->toBe(1)
        ->and(Storage::disk($version->disk)->get($version->storage_path))->toBe($bytes)
        ->and(DB::table('prc_process_work')->where('stage', 'download')->where('state', 'succeeded')->count())->toBe(2)
        ->and(PackageNetworkFacts::attempts()->count())->toBe(2);
})->with([false, true]);
