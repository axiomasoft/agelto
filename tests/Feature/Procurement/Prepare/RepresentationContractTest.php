<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use App\Services\Procurement\Prepare\DocumentPreparer;
use App\Services\Procurement\Prepare\PassthroughStrategy;
use App\Services\Procurement\Prepare\PreparationStrategy;
use App\Services\Procurement\Prepare\RepresentationResolver;
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

test('цепочка подготовки: первая стратегия вернула false — вызывается следующая', function () {
    config(['ai.prepare.strategies' => [RefusingStrategy::class, AcceptingStrategy::class]]);
    RefusingStrategy::$calls = 0;
    AcceptingStrategy::$calls = 0;

    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    app(DocumentPreparer::class)->prepare($document, $version);

    expect(RefusingStrategy::$calls)->toBe(1)
        ->and(AcceptingStrategy::$calls)->toBe(1)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Downloaded);
});

test('цепочка подготовки: стратегия вернула true — следующая не вызывается', function () {
    config(['ai.prepare.strategies' => [AcceptingStrategy::class, RefusingStrategy::class]]);
    RefusingStrategy::$calls = 0;
    AcceptingStrategy::$calls = 0;

    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    app(DocumentPreparer::class)->prepare($document, $version);

    expect(AcceptingStrategy::$calls)->toBe(1)
        ->and(RefusingStrategy::$calls)->toBe(0);
});

test('цепочка исчерпана — unprocessable(conversion_failed) с перечислением испробованных стратегий', function () {
    config(['ai.prepare.strategies' => [RefusingStrategy::class]]);
    RefusingStrategy::$calls = 0;

    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    app(DocumentPreparer::class)->prepare($document, $version);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('conversion_failed')
        ->and($document->last_error)->toContain('RefusingStrategy');
});

test('PassthroughStrategy замыкает цепочку успехом — оригинал валидный вход', function () {
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    expect(app(PassthroughStrategy::class)->process($document, $version))->toBeTrue();
});

test('исключение стратегии не маскируется под «не подошла» — пробрасывается наружу', function () {
    config(['ai.prepare.strategies' => [ThrowingStrategy::class, AcceptingStrategy::class]]);
    AcceptingStrategy::$calls = 0;

    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    expect(fn () => app(DocumentPreparer::class)->prepare($document, $version))
        ->toThrow(RuntimeException::class, 'стратегия упала');

    expect(AcceptingStrategy::$calls)->toBe(0)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Downloaded);
});

final class RefusingStrategy implements PreparationStrategy
{
    public static int $calls = 0;

    public function canProcess(DocumentVersion $version): bool
    {
        return true;
    }

    public function process(Document $document, DocumentVersion $version): bool
    {
        self::$calls++;

        return false;
    }
}

final class AcceptingStrategy implements PreparationStrategy
{
    public static int $calls = 0;

    public function canProcess(DocumentVersion $version): bool
    {
        return true;
    }

    public function process(Document $document, DocumentVersion $version): bool
    {
        self::$calls++;

        return true;
    }
}

final class ThrowingStrategy implements PreparationStrategy
{
    public function canProcess(DocumentVersion $version): bool
    {
        return true;
    }

    public function process(Document $document, DocumentVersion $version): bool
    {
        throw new RuntimeException('стратегия упала');
    }
}
