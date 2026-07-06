<?php

declare(strict_types=1);

use App\Enums\Procurement\DocumentStatus;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\PipelineDispatcher;

test('dispatch отбирает скачанные документы и пропускает недоскачанные/снятые', function () {
    $procurement = Procurement::factory()->create();
    $downloaded = Document::factory()->create(['procurement_id' => $procurement->id, 'status' => DocumentStatus::Downloaded]);
    Document::factory()->create(['procurement_id' => $procurement->id, 'status' => DocumentStatus::Discovered]);
    Document::factory()->create(['procurement_id' => $procurement->id, 'status' => DocumentStatus::Downloaded, 'removed_at' => now()]);

    $count = app(PipelineDispatcher::class)->dispatch(procurement: $procurement);

    expect($count)->toBe(1)
        ->and($downloaded->refresh()->status)->toBe(DocumentStatus::Downloaded);
});

test('повторный dispatch не плодит доп. документов и не меняет статус', function () {
    $procurement = Procurement::factory()->create();
    Document::factory()->create(['procurement_id' => $procurement->id, 'status' => DocumentStatus::Downloaded]);

    $dispatcher = app(PipelineDispatcher::class);
    $first = $dispatcher->dispatch(procurement: $procurement);
    $second = $dispatcher->dispatch(procurement: $procurement);

    expect($first)->toBe(1)
        ->and($second)->toBe(1)
        ->and(Document::query()->where('procurement_id', $procurement->id)->count())->toBe(1);
});
