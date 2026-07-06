<?php

declare(strict_types=1);

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Models\Procurement\Analysis;
use App\Models\Procurement\Document;
use App\Models\Procurement\DocumentVersion;

/**
 * §11 «Документ заменён» и «Архив в документе».
 */
test('документ заменяется новой версией по тому же external_id, анализы старой версии остаются', function () {
    $document = Document::factory()->create(['external_id' => 'eis-doc-guid-001']);

    $firstVersion = DocumentVersion::factory()->create(['document_id' => $document->id]);
    $analysisOfFirstVersion = Analysis::factory()->create([
        'document_version_id' => $firstVersion->id,
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
    ]);

    $secondVersion = DocumentVersion::factory()->create(['document_id' => $document->id]);

    expect(Document::query()->where('external_id', 'eis-doc-guid-001')->count())->toBe(1)
        ->and(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(2)
        ->and($secondVersion->id)->not->toBe($firstVersion->id);

    $analysisOfFirstVersion->refresh();
    expect($analysisOfFirstVersion->document_version_id)->toBe($firstVersion->id)
        ->and($analysisOfFirstVersion->status)->toBe(AnalysisStatus::Succeeded);
});

test('архив документа распаковывается в новые doc_documents со связью через parent_version_id', function () {
    $archiveDocument = Document::factory()->create(['kind' => 'архив', 'external_id' => 'eis-archive-guid-001']);
    $archiveVersion = DocumentVersion::factory()->create([
        'document_id' => $archiveDocument->id,
        'extension' => 'zip',
        'mime' => 'application/zip',
    ]);

    $extractedDocument = Document::factory()->create([
        'procurement_id' => $archiveDocument->procurement_id,
        'kind' => 'смета',
        'external_id' => 'eis-archive-guid-001#смета.pdf',
    ]);
    $extractedVersion = DocumentVersion::factory()->create([
        'document_id' => $extractedDocument->id,
        'parent_version_id' => $archiveVersion->id,
    ]);

    expect($extractedVersion->parent_version_id)->toBe($archiveVersion->id)
        ->and($extractedDocument->id)->not->toBe($archiveDocument->id)
        ->and(Document::query()->where('procurement_id', $archiveDocument->procurement_id)->count())->toBe(2);
});
