<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Procurement\DocumentIndexer;
use App\Services\Procurement\Eis\Card\DocumentsParser;

function documentsFixtureDataForIndexerTest(): array
{
    $html = file_get_contents(base_path('tests/Fixtures/Crawler/Card/documents.html'));

    return app(DocumentsParser::class)->parse($html);
}

test('index создаёт документы со статусом discovered', function () {
    $procurement = Procurement::factory()->create();

    app(DocumentIndexer::class)->index($procurement, documentsFixtureDataForIndexerTest());

    expect(Document::query()->where('procurement_id', $procurement->id)->count())->toBe(6);

    $notice = Document::query()->where('external_id', 'F160E393C87D4C65ACEDF22204D1A9C9')->firstOrFail();

    expect($notice->status)->toBe(DocumentStatus::Discovered)
        ->and($notice->title)->toBe('НМЦК размещение информационного сообщения на страницах на 2025 год.xls');
});

test('index идемпотентен: повтор не плодит документы и не сбрасывает статус', function () {
    $procurement = Procurement::factory()->create();
    $documents = documentsFixtureDataForIndexerTest();

    app(DocumentIndexer::class)->index($procurement, $documents);

    $notice = Document::query()->where('external_id', 'F160E393C87D4C65ACEDF22204D1A9C9')->firstOrFail();
    $notice->update(['status' => DocumentStatus::Downloaded]);

    app(DocumentIndexer::class)->index($procurement, $documents);

    expect(Document::query()->where('procurement_id', $procurement->id)->count())->toBe(6);
    expect($notice->refresh()->status)->toBe(DocumentStatus::Downloaded);
});

test('index помечает removed_at документ, снятый с карточки, и пишет событие document_removed', function () {
    $procurement = Procurement::factory()->create();
    $documents = documentsFixtureDataForIndexerTest();

    app(DocumentIndexer::class)->index($procurement, $documents);

    // Второй прогон — только первый документ, остальные пропали с карточки.
    app(DocumentIndexer::class)->index($procurement, [$documents[0]]);

    $removed = Document::query()->where('external_id', '9150E995B84D4E8481913B1EC0654651')->firstOrFail();

    expect($removed->removed_at)->not->toBeNull();

    expect(ProcurementEvent::query()
        ->where('procurement_id', $procurement->id)
        ->where('event_type', ProcurementEventType::DocumentRemoved)
        ->count())->toBe(5);
});
