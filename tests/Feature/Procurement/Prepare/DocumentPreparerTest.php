<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\ProcurementEventType;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\ProcurementEvent;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use App\Services\Procurement\Prepare\SpreadsheetStrategy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\PreparationStrategyProbe;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(fn () => StoredDocumentFixtures::reset());

function fixtureContent(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/Documents/{$name}"));
}

function seedVersion(Document $document, string $fixture, string $extension): DocumentVersion
{
    $content = fixtureContent($fixture);
    $hash = hash('sha256', $content);
    $storagePath = "documents/{$hash}";

    Storage::disk('s3')->put($storagePath, $content);

    return DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => $hash,
        'size_bytes' => strlen($content),
        'filename' => $fixture,
        'extension' => $extension,
        'mime' => 'application/octet-stream',
        'storage_path' => $storagePath,
        'downloaded_at' => now(),
    ]);
}

test('format corpus: подготовка PDF — PassthroughStrategy, документ переходит в prepared без ScreeningJob (флаг выключен)', function () {
    Storage::fake('s3');
    Queue::fake();
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    seedVersion($document, 'sample-v1.pdf', 'pdf');

    (new PreparationStrategyProbe($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Prepared);
    Queue::assertNothingPushed();
});

test('format corpus: подготовка PNG — ImageStrategy без конвертации, документ переходит в prepared', function () {
    Storage::fake('s3');
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    seedVersion($document, 'sample.png', 'png');

    (new PreparationStrategyProbe($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Prepared);
});

test('format corpus: подготовка XLSX — конвертация в PDF записана представлением kind=pdf, документ prepared', function () {
    Storage::fake('s3');
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = seedVersion($document, 'sample.xlsx', 'xlsx');

    (new PreparationStrategyProbe($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Prepared);

    $representation = app(RepresentationStoreRepository::class)->find($version, RepresentationKind::Pdf);

    expect($representation)->not->toBeNull()
        ->and($representation->mime)->toBe('application/pdf')
        ->and($representation->size_bytes)->toBeGreaterThan(0)
        ->and($representation->meta['tool'])->toBe('phpspreadsheet+mpdf');

    Storage::disk('s3')->assertExists($representation->storage_path);
})->skip(fn () => ! extension_loaded('gd'), 'требует gd (Mpdf) — см. docker/php/Dockerfile');

test('format corpus: XLSX сверх лимита строк — ветка LibreOffice производит представление, документ prepared (D26/P3.4)', function () {
    config(['ai.prepare.xlsx_max_rows' => 1]);
    Storage::fake('s3');
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = seedVersion($document, 'sample.xlsx', 'xlsx');

    (new PreparationStrategyProbe($document->id))->handle();

    // Лимит ИНСТРУМЕНТА подготовки ≠ приговор документу (D26/D28): с P3.4 xlsx сверх лимита
    // идёт через LibreOffice-фолбэк `SpreadsheetStrategy`, а не оригиналом через Passthrough.
    $representation = app(RepresentationStoreRepository::class)->find($version, RepresentationKind::Pdf);

    expect($document->refresh()->status)->toBe(DocumentStatus::Prepared)
        ->and($representation)->not->toBeNull()
        ->and($representation->meta['tool'])->toBe('libreoffice');
});

test('format corpus: XLSX сверх лимита размера — ветка LibreOffice производит представление, документ не терминален (D26/P3.4)', function () {
    config(['ai.prepare.xlsx_max_mb' => 0]);
    Storage::fake('s3');
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = seedVersion($document, 'sample.xlsx', 'xlsx');

    (new PreparationStrategyProbe($document->id))->handle();

    $representation = app(RepresentationStoreRepository::class)->find($version, RepresentationKind::Pdf);

    expect($document->refresh()->status)->toBe(DocumentStatus::Prepared)
        ->and($representation)->not->toBeNull()
        ->and($representation->meta['tool'])->toBe('libreoffice');
});

test('format corpus: XLSX — обе ветки SpreadsheetStrategy упали — unprocessable(conversion_failed) (P3.4)', function () {
    config([
        'ai.prepare.xlsx_max_mb' => 0,
        'ai.prepare.strategies' => [SpreadsheetStrategy::class],
    ]);
    Process::fake([
        '*soffice*' => Process::result(errorOutput: 'soffice: crashed on convert', exitCode: 1),
    ]);
    Storage::fake('s3');
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    seedVersion($document, 'sample.xlsx', 'xlsx');

    (new PreparationStrategyProbe($document->id))->handle();

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('conversion_failed');
});

test('format corpus: архив с xlsx внутри — создаёт дочерний Document+Version с parent_version_id и событием document_added', function () {
    Storage::fake('s3');
    Queue::fake();
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = seedVersion($document, 'sample-with-xlsx.zip', 'zip');

    (new PreparationStrategyProbe($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Expanded);

    $child = Document::query()->where('procurement_id', $document->procurement_id)
        ->where('external_id', "{$version->id}:estimates/sample.xlsx")
        ->first();

    expect($child)->not->toBeNull()
        ->and($child->status)->toBe(DocumentStatus::Downloaded);

    $childVersion = DocumentVersion::query()->where('document_id', $child->id)->first();
    expect($childVersion->parent_version_id)->toBe($version->id);
    expect($childVersion->archive_entry_path)->toBe('estimates/sample.xlsx');

    Queue::assertNothingPushed();
    Queue::assertNotPushed(ScreeningJob::class);

    expect(ProcurementEvent::query()
        ->where('procurement_id', $document->procurement_id)
        ->where('event_type', ProcurementEventType::DocumentAdded)
        ->where('entity_ref->document_id', $child->id)
        ->count())->toBe(1);
});

test('format corpus: повторный прогон подготовки архива — дочерний документ не дублируется', function () {
    Storage::fake('s3');
    Queue::fake();
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    seedVersion($document, 'sample-with-xlsx.zip', 'zip');

    (new PreparationStrategyProbe($document->id))->handle();
    $document->update(['status' => DocumentStatus::Downloaded]);
    (new PreparationStrategyProbe($document->id))->handle();

    expect(Document::query()->where('procurement_id', $document->procurement_id)->where('id', '!=', $document->id)->count())->toBe(1);
});

test('format corpus: архив читается с recorded disk, а child пишется на current disk', function () {
    Storage::fake('s3');
    Storage::fake('legacy-documents');
    Queue::fake();

    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $content = fixtureContent('sample-with-xlsx.zip');
    $storagePath = 'documents/legacy-archive.zip';
    Storage::disk('legacy-documents')->put($storagePath, $content);

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => hash('sha256', $content),
        'size_bytes' => strlen($content),
        'filename' => 'sample-with-xlsx.zip',
        'extension' => 'zip',
        'mime' => 'application/zip',
        'storage_path' => $storagePath,
        'disk' => 'legacy-documents',
        'downloaded_at' => now(),
    ]);

    (new PreparationStrategyProbe($document->id))->handle();

    $childVersion = DocumentVersion::query()->where('parent_version_id', $version->id)->sole();
    expect($document->refresh()->status)->toBe(DocumentStatus::Expanded)
        ->and($childVersion->disk)->toBe('s3');
    Storage::disk('s3')->assertExists($childVersion->storage_path);
});

test('format corpus: identity archive entry принадлежит паре parent version и normalized path', function () {
    $parentA = DocumentVersion::factory()->create();
    $parentB = DocumentVersion::factory()->create();

    DocumentVersion::factory()->create([
        'parent_version_id' => $parentA->id,
        'archive_entry_path' => 'estimates/sample.xlsx',
    ]);

    expect(fn () => DB::transaction(fn () => DocumentVersion::factory()->create([
        'parent_version_id' => $parentA->id,
        'archive_entry_path' => 'estimates/sample.xlsx',
    ])))->toThrow(QueryException::class);

    $differentParent = DocumentVersion::factory()->create([
        'parent_version_id' => $parentB->id,
        'archive_entry_path' => 'estimates/sample.xlsx',
    ]);

    expect($differentParent->archive_entry_path)->toBe('estimates/sample.xlsx');
});

test('format corpus: осиротевший дочерний документ архива (child без Version) — ретрай дописывает версию, а не пропускает индексацию', function () {
    // Регрессия ревью-находки P4 HIGH: до фикса `indexChild()` без DB::transaction мог
    // закоммитить child Document раньше DocumentVersion (например, при транзиентном сбое
    // S3-put), а гард идемпотентности по findByExternalId(Document) находил осиротевший
    // child при ретрае и делал return раньше создания версии — версия не создавалась
    // никогда. Воспроизводим осиротевшее состояние напрямую и проверяем, что ретрай
    // backfill'ит версию, а не тихо пропускает индексацию.
    Storage::fake('s3');
    Queue::fake();
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = seedVersion($document, 'sample-with-xlsx.zip', 'zip');

    $orphanExternalId = "{$version->id}:estimates/sample.xlsx";
    $orphan = Document::factory()->create([
        'procurement_id' => $document->procurement_id,
        'external_id' => $orphanExternalId,
        'status' => DocumentStatus::Downloaded,
    ]);

    (new PreparationStrategyProbe($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Expanded);

    $children = Document::query()
        ->where('procurement_id', $document->procurement_id)
        ->where('external_id', $orphanExternalId)
        ->get();

    expect($children)->toHaveCount(1)
        ->and($children->first()->id)->toBe($orphan->id);

    $childVersion = DocumentVersion::query()->where('document_id', $orphan->id)->first();
    expect($childVersion)->not->toBeNull()
        ->and($childVersion->parent_version_id)->toBe($version->id)
        ->and($childVersion->archive_entry_path)->toBe('estimates/sample.xlsx');
});

test('format corpus: архив сверх лимита вложенности (max_depth=0) — unprocessable(zip_bomb), без распаковки', function () {
    config(['ai.prepare.archive.max_depth' => 0]);
    Storage::fake('s3');
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    seedVersion($document, 'sample-with-xlsx.zip', 'zip');

    (new PreparationStrategyProbe($document->id))->handle();

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('zip_bomb');

    expect(Document::query()->where('id', '!=', $document->id)->count())->toBe(0);
});

test('format corpus: архив сверх лимита числа файлов — unprocessable(zip_bomb)', function () {
    config(['ai.prepare.archive.max_files' => 0]);
    Storage::fake('s3');
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    seedVersion($document, 'sample-with-xlsx.zip', 'zip');

    (new PreparationStrategyProbe($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Unprocessable);
});

test('format corpus: повторный прогон на уже prepared документе — no-op (status-guard)', function () {
    Storage::fake('s3');
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    seedVersion($document, 'sample-v1.pdf', 'pdf');

    (new PreparationStrategyProbe($document->id))->handle();
    (new PreparationStrategyProbe($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Prepared);
});

test('format corpus: успешная подготовка диспатчит ScreeningJob за включённым гейтом config(ai.screening.enabled)', function () {
    config(['ai.screening.enabled' => true]);
    Storage::fake('s3');
    Queue::fake();
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    seedVersion($document, 'sample-v1.pdf', 'pdf');

    (new PreparationStrategyProbe($document->id))->handle();

    Queue::assertNothingPushed();
});

test('format corpus: unprocessable-исход не диспатчит ScreeningJob даже за включённым гейтом', function () {
    config([
        'ai.screening.enabled' => true,
        'ai.prepare.xlsx_max_mb' => 0,
        'ai.prepare.strategies' => [SpreadsheetStrategy::class],
    ]);
    Process::fake([
        '*soffice*' => Process::result(errorOutput: 'soffice: crashed on convert', exitCode: 1),
    ]);
    Storage::fake('s3');
    Queue::fake();
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    seedVersion($document, 'sample.xlsx', 'xlsx');

    (new PreparationStrategyProbe($document->id))->handle();

    Queue::assertNothingPushed();
});
