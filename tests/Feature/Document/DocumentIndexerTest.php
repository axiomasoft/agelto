<?php

declare(strict_types=1);

use App\Dto\Procurement\Details\DocumentData;
use App\Dto\Procurement\Details\DocumentsSnapshot;
use App\Enums\Procurement\DetailSnapshotStatus;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Procurement\DocumentIndexer;
use App\Services\Procurement\Eis\Card\DocumentsParser;

function documentsFixtureSnapshotForIndexerTest(): DocumentsSnapshot
{
    $html = file_get_contents(base_path('tests/Fixtures/Crawler/Card/documents.html'));

    return app(DocumentsParser::class)->parse($html);
}

test('index создаёт документы со статусом discovered', function () {
    $procurement = Procurement::factory()->create();

    app(DocumentIndexer::class)->index(procurement: $procurement, snapshot: documentsFixtureSnapshotForIndexerTest());

    expect(Document::query()->where('procurement_id', $procurement->id)->count())->toBe(6);

    $notice = Document::query()->where('external_id', 'F160E393C87D4C65ACEDF22204D1A9C9')->firstOrFail();

    expect($notice->status)->toBe(DocumentStatus::Discovered)
        ->and($notice->title)->toBe('НМЦК размещение информационного сообщения на страницах на 2025 год.xls');
});

test('index идемпотентен: повтор не плодит документы и не сбрасывает статус', function () {
    $procurement = Procurement::factory()->create();
    $snapshot = documentsFixtureSnapshotForIndexerTest();

    app(DocumentIndexer::class)->index(procurement: $procurement, snapshot: $snapshot);

    $notice = Document::query()->where('external_id', 'F160E393C87D4C65ACEDF22204D1A9C9')->firstOrFail();
    $notice->update(['status' => DocumentStatus::Downloaded]);

    app(DocumentIndexer::class)->index(procurement: $procurement, snapshot: $snapshot);

    expect(Document::query()->where('procurement_id', $procurement->id)->count())->toBe(6);
    expect($notice->refresh()->status)->toBe(DocumentStatus::Downloaded);
});

test('повторный uid обновляет метаданные, но не сбрасывает статус и не создаёт новую версию', function () {
    $procurement = Procurement::factory()->create();
    $snapshot = documentsFixtureSnapshotForIndexerTest();
    $source = $snapshot->documents[0];

    app(DocumentIndexer::class)->index(procurement: $procurement, snapshot: $snapshot);

    $document = Document::query()->where('external_id', $source->externalId)->firstOrFail();
    $document->update(['status' => DocumentStatus::Downloaded]);
    DocumentVersion::factory()->create(['document_id' => $document->id]);
    $documents = $snapshot->documents;
    $documents[0] = new DocumentData(
        title: 'Обновлённое название при прежнем uid.pdf',
        kind: $source->kind,
        sourceUrl: 'https://zakupki.gov.ru/filestore/file.html?uid='.$source->externalId,
        externalId: $source->externalId,
    );

    app(DocumentIndexer::class)->index(
        procurement: $procurement,
        snapshot: new DocumentsSnapshot(
            status: DetailSnapshotStatus::Complete,
            documents: $documents,
        ),
    );

    expect($document->refresh()->title)->toBe('Обновлённое название при прежнем uid.pdf')
        ->and($document->source_url)->toBe('https://zakupki.gov.ru/filestore/file.html?uid='.$source->externalId)
        ->and($document->status)->toBe(DocumentStatus::Downloaded)
        ->and(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(1);
});

test('index помечает removed_at документ, снятый с карточки, и пишет событие document_removed', function () {
    $procurement = Procurement::factory()->create();
    $snapshot = documentsFixtureSnapshotForIndexerTest();

    app(DocumentIndexer::class)->index(procurement: $procurement, snapshot: $snapshot);

    // Второй прогон — только первый документ, остальные пропали с карточки.
    app(DocumentIndexer::class)->index(
        procurement: $procurement,
        snapshot: new DocumentsSnapshot(
            status: DetailSnapshotStatus::Complete,
            documents: [$snapshot->documents[0]],
        ),
    );

    $removed = Document::query()->where('external_id', '9150E995B84D4E8481913B1EC0654651')->firstOrFail();

    expect($removed->removed_at)->not->toBeNull();

    expect(ProcurementEvent::query()
        ->where('procurement_id', $procurement->id)
        ->where('event_type', ProcurementEventType::DocumentRemoved)
        ->count())->toBe(5);
});

test('index не снимает platform attachments при incomplete snapshot', function () {
    $procurement = Procurement::factory()->create();
    $snapshot = documentsFixtureSnapshotForIndexerTest();

    app(DocumentIndexer::class)->index(procurement: $procurement, snapshot: $snapshot);
    app(DocumentIndexer::class)->index(
        procurement: $procurement,
        snapshot: new DocumentsSnapshot(
            status: DetailSnapshotStatus::Incomplete,
            documents: [$snapshot->documents[0]],
        ),
    );

    expect(Document::query()->where('procurement_id', $procurement->id)->whereNull('removed_at')->count())->toBe(6)
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $procurement->id)
            ->where('event_type', ProcurementEventType::DocumentRemoved)
            ->count())->toBe(0);
});

test('index сохраняет archive child при complete reindex неизменённого platform attachment', function () {
    $procurement = Procurement::factory()->create();
    $snapshot = documentsFixtureSnapshotForIndexerTest();

    app(DocumentIndexer::class)->index(procurement: $procurement, snapshot: $snapshot);

    $parent = Document::query()->where('procurement_id', $procurement->id)->firstOrFail();
    $parentVersion = DocumentVersion::factory()->create(['document_id' => $parent->id]);
    $child = Document::factory()->create([
        'procurement_id' => $procurement->id,
        'platform_id' => $parent->platform_id,
        'external_id' => "{$parentVersion->id}:archive/estimate.pdf",
    ]);
    DocumentVersion::factory()->create([
        'document_id' => $child->id,
        'parent_version_id' => $parentVersion->id,
        'archive_entry_path' => 'archive/estimate.pdf',
    ]);

    app(DocumentIndexer::class)->index(procurement: $procurement, snapshot: $snapshot);

    expect($parent->refresh()->removed_at)->toBeNull()
        ->and($child->refresh()->removed_at)->toBeNull();
});
