<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use App\Services\Procurement\Prepare\DocumentFormatRegistry;
use App\Services\Procurement\Prepare\RepresentationResolver;
use App\Services\Procurement\Prepare\ScanStrategy;
use Illuminate\Database\QueryException;

/**
 * Контракт представлений (P3.1, D25–D28): носитель, идемпотентность записи, дефолтная политика
 * резолвера и цепочка стратегий подготовки.
 */
test('повторный storeOrUpdate по паре версия+kind обновляет ту же запись, а не плодит вторую', function () {
    $version = DocumentVersion::factory()->create();
    $repository = app(RepresentationStoreRepository::class);

    $first = $repository->storeOrUpdate($version, RepresentationKind::Pdf, [
        'storage_path' => 'documents/aa/first.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 100,
        'generated_at' => now(),
    ]);

    $second = $repository->storeOrUpdate($version, RepresentationKind::Pdf, [
        'storage_path' => 'documents/aa/second.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 200,
        'generated_at' => now(),
    ]);

    expect($second->id)->toBe($first->id)
        ->and($second->storage_path)->toBe('documents/aa/second.pdf')
        ->and($second->size_bytes)->toBe(200)
        ->and(DocumentRepresentation::query()->where('document_version_id', $version->id)->count())->toBe(1);
});

test('пара версия+kind уникальна на уровне схемы — вторая запись тем же kind отбивается БД', function () {
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
        'disk' => (string) config('crawler.documents_disk'),
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
        'disk' => null,
        'mime' => 'application/pdf',
    ]);

    expect(app(RepresentationResolver::class)->forLlm($version))->toBe([
        'storagePath' => 'documents/bb/original.prepared.pdf',
        'disk' => (string) config('crawler.documents_disk'),
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
