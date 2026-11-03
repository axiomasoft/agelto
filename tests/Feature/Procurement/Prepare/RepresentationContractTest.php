<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use App\Services\Procurement\Prepare\DocumentFormatRegistry;
use App\Services\Procurement\Prepare\RepresentationResolver;
use App\Services\Procurement\Prepare\ScanStrategy;
use App\Services\Storage\FileLocationResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(fn () => StoredDocumentFixtures::reset());

/**
 * Контракт представлений (P3.1, D25–D28): носитель, идемпотентность записи, дефолтная политика
 * резолвера и цепочка стратегий подготовки.
 */
test('повторный storeOrUpdate идемпотентен по recipe kind range и отвергает изменение immutable bytes', function () {
    $version = DocumentVersion::factory()->create();
    $repository = app(RepresentationStoreRepository::class);
    $disk = app(FileLocationResolver::class)->currentDocumentsDisk();
    Storage::disk($disk)->put('documents/aa/first.pdf', str_repeat('a', 100));
    Storage::disk($disk)->put('documents/aa/second.pdf', str_repeat('b', 200));
    $attributes = ['storage_path' => 'documents/aa/first.pdf', 'disk' => $disk, 'mime' => 'application/pdf', 'size_bytes' => 100, 'generated_at' => now()];
    $first = $repository->storeOrUpdate($version, RepresentationKind::Pdf, $attributes);
    $second = $repository->storeOrUpdate($version, RepresentationKind::Pdf, $attributes);
    expect($second->id)->toBe($first->id)->and($second->size_bytes)->toBe(100);
    $changed = [...$attributes, 'storage_path' => 'documents/aa/second.pdf', 'size_bytes' => 200];
    expect(fn () => $repository->storeOrUpdate($version, RepresentationKind::Pdf, $changed))->toThrow(LogicException::class, 'Immutable representation');
    expect($first->refresh()->storage_path)->toBe('documents/aa/first.pdf')
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(1);
    $newRecipe = $repository->storeOrUpdate($version, RepresentationKind::Pdf, [...$changed, 'meta' => ['tool_version' => '2']]);
    expect($newRecipe->id)->not->toBe($first->id)->and($newRecipe->size_bytes)->toBe(200)
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(2);
});

test('tuple version recipe kind range уникален на уровне схемы — повтор отбивается БД', function () {
    $version = DocumentVersion::factory()->create();

    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::Pdf,
    ]);

    expect(fn () => DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::Pdf,
    ]))->toThrow(QueryException::class);
});

test('разные kind одной версии живут рядом, allFor возвращает оба', function () {
    $version = DocumentVersion::factory()->create();

    DocumentRepresentation::factory()->create(['document_version_id' => $version->id, 'kind' => RepresentationKind::Pdf]);
    DocumentRepresentation::factory()->create(['document_version_id' => $version->id, 'kind' => RepresentationKind::TextGrid]);

    expect(app(RepresentationStoreRepository::class)->allFor($version))->toHaveCount(2);
});

test('резолвер без представлений отдаёт оригинал версии', function () {
    $version = DocumentVersion::factory()->create([
        'extension' => 'xlsx',
        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'storage_path' => 'documents/bb/original.xlsx',
    ]);

    expect(app(RepresentationResolver::class)->forLlm($version))->toBe([
        'storagePath' => 'documents/bb/original.xlsx',
        'disk' => (string) config('documents.disk'),
        'extension' => 'xlsx',
        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]);
});

test('резолвер с представлением pdf отдаёт его вместо оригинала', function () {
    $version = DocumentVersion::factory()->create([
        'extension' => 'xlsx',
        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'storage_path' => 'documents/bb/original.xlsx',
    ]);

    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::Pdf,
        'storage_path' => 'documents/bb/original.prepared.pdf',
        'disk' => app(FileLocationResolver::class)->currentDocumentsDisk(),
        'mime' => 'application/pdf',
    ]);

    expect(app(RepresentationResolver::class)->forLlm($version))->toBe([
        'storagePath' => 'documents/bb/original.prepared.pdf',
        'disk' => (string) config('documents.disk'),
        'extension' => 'pdf',
        'mime' => 'application/pdf',
    ]);
});

test('резолвер отдаёт диск представления, если тот записан явно (производные эталонов P6)', function () {
    $version = DocumentVersion::factory()->create();

    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::Pdf,
        'storage_path' => 'EST-XLSX-AKT-001/derived/whole.pdf',
        'disk' => 'estimates',
    ]);

    expect(app(RepresentationResolver::class)->forLlm($version)['disk'])->toBe('estimates');
});

test('registry закрывает known extensions и не даёт catch-all для неизвестного формата', function () {
    $registry = app(DocumentFormatRegistry::class);

    expect($registry->forExtension('pdf')['strategy'])->toBe(ScanStrategy::class)
        ->and($registry->forExtension('zip')['result']->value)->toBe('expanded_container')
        ->and($registry->forExtension('unknown'))->toBeNull();
});

test('surviving kind reader выбирает latest complete recipe и не принимает page batch как полное представление', function (): void {
    $version = DocumentVersion::factory()->create();
    DocumentRepresentation::factory()->create(['document_version_id' => $version->id, 'kind' => RepresentationKind::Pdf, 'storage_path' => 'complete-old.pdf']);
    $current = DocumentRepresentation::factory()->create(['document_version_id' => $version->id, 'kind' => RepresentationKind::Pdf, 'storage_path' => 'complete-new.pdf', 'preparation_recipe_hash' => hash('sha256', 'new recipe')]);
    DocumentRepresentation::factory()->create(['document_version_id' => $version->id, 'kind' => RepresentationKind::Pdf, 'storage_path' => 'latest-batch.pdf', 'range_key' => 'pages:0:1']);
    expect(app(RepresentationStoreRepository::class)->find($version, RepresentationKind::Pdf)->id)->toBe($current->id);
});
