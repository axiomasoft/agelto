<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Prepare\RepresentationResolver;

/**
 * Правило выбора представления как данные (P6.3, D3/D52, ADR `adr-input-representation.md`):
 * `RepresentationResolver::forLlm()` читает `config('ai.representations')` вместо константы.
 */
test('политика по формату выбрана из конфига — подменённый config() меняет результат резолвера', function () {
    $version = DocumentVersion::factory()->create(['extension' => 'xlsx']);

    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::Pdf,
        'storage_path' => 'documents/policy/pdf.pdf',
        'mime' => 'application/pdf',
    ]);
    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::PdfAlt,
        'storage_path' => 'documents/policy/pdf-alt.pdf',
        'mime' => 'application/pdf',
    ]);

    config(['ai.representations.policy.xlsx' => ['pdf_alt', 'pdf']]);
    expect(app(RepresentationResolver::class)->forLlm($version)['storagePath'])->toBe('documents/policy/pdf-alt.pdf');

    config(['ai.representations.policy.xlsx' => ['pdf']]);
    expect(app(RepresentationResolver::class)->forLlm($version)['storagePath'])->toBe('documents/policy/pdf.pdf');
});

test('отсутствие всех kind ов политики отдаёт оригинал версии при fallback_to_source = true', function () {
    $version = DocumentVersion::factory()->create([
        'extension' => 'xlsx',
        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'storage_path' => 'documents/policy/original.xlsx',
    ]);

    config([
        'ai.representations.policy.xlsx' => ['pdf_alt', 'pdf'],
        'ai.representations.fallback_to_source' => true,
    ]);

    expect(app(RepresentationResolver::class)->forLlm($version))->toBe([
        'storagePath' => 'documents/policy/original.xlsx',
        'disk' => (string) config('crawler.documents_disk'),
        'extension' => 'xlsx',
        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]);
});

test('отсутствие всех kind ов политики отдаёт null при fallback_to_source = false', function () {
    $version = DocumentVersion::factory()->create(['extension' => 'xlsx']);

    config([
        'ai.representations.policy.xlsx' => ['pdf_alt', 'pdf'],
        'ai.representations.fallback_to_source' => false,
    ]);

    expect(app(RepresentationResolver::class)->forLlm($version))->toBeNull();
});

test('forceKind перебивает политику даже если политика указывает на другой kind', function () {
    $version = DocumentVersion::factory()->create(['extension' => 'xlsx']);

    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::Pdf,
        'storage_path' => 'documents/policy/forced.pdf',
        'mime' => 'application/pdf',
    ]);

    config(['ai.representations.policy.xlsx' => ['pdf_alt']]);

    $forced = app(RepresentationResolver::class)->forLlm($version, RepresentationKind::Pdf);

    expect($forced['storagePath'])->toBe('documents/policy/forced.pdf');
});

test('формат вне карты policy идёт по default', function () {
    $version = DocumentVersion::factory()->create(['extension' => 'docx']);

    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::Pdf,
        'storage_path' => 'documents/policy/docx-default.pdf',
        'mime' => 'application/pdf',
    ]);

    config(['ai.representations.policy' => [
        'xlsx' => ['pdf_alt', 'pdf'],
        'default' => ['pdf'],
    ]]);

    expect(app(RepresentationResolver::class)->forLlm($version)['storagePath'])->toBe('documents/policy/docx-default.pdf');
});
