<?php

declare(strict_types=1);

use App\Enums\Procurement\DocumentStatus;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\PipelineDispatcher;
use Illuminate\Support\Facades\Queue;

test('dispatch отбирает скачанные документы, диспатчит PrepareDocumentJob и пропускает недоскачанные/снятые', function () {
    Queue::fake();
    $procurement = Procurement::factory()->create();
    $downloaded = Document::factory()->create(['procurement_id' => $procurement->id, 'status' => DocumentStatus::Downloaded]);
    Document::factory()->create(['procurement_id' => $procurement->id, 'status' => DocumentStatus::Discovered]);
    Document::factory()->create(['procurement_id' => $procurement->id, 'status' => DocumentStatus::Downloaded, 'removed_at' => now()]);

    $count = app(PipelineDispatcher::class)->dispatch(procurement: $procurement);

    expect($count)->toBe(1)
        ->and($downloaded->refresh()->status)->toBe(DocumentStatus::Downloaded);
    Queue::assertPushed(PrepareDocumentJob::class, fn (PrepareDocumentJob $job) => $job->documentId === $downloaded->id);
    Queue::assertPushed(PrepareDocumentJob::class, 1);
});

test('повторный dispatch не плодит доп. документов, не меняет статус и не дублирует job в очереди (ShouldBeUnique)', function () {
    Queue::fake();
    $procurement = Procurement::factory()->create();
    Document::factory()->create(['procurement_id' => $procurement->id, 'status' => DocumentStatus::Downloaded]);

    $dispatcher = app(PipelineDispatcher::class);
    $first = $dispatcher->dispatch(procurement: $procurement);
    $second = $dispatcher->dispatch(procurement: $procurement);

    expect($first)->toBe(1)
        ->and($second)->toBe(1)
        ->and(Document::query()->where('procurement_id', $procurement->id)->count())->toBe(1);
    // `dispatch()` сам по себе не дедуплицирует enqueue (оба вызова видят один и тот же
    // downloaded-документ и оба пытаются диспатчнуть) — анти-дубль в очереди даёт
    // `ShouldBeUnique` на `PrepareDocumentJob` (P4.1 D39): `Illuminate\Foundation\Bus\PendingDispatch`
    // проверяет `UniqueLock` ДО похода в очередь (даже под `Queue::fake()`), второй вызов с тем же
    // `uniqueId()` лока не берёт — реально запушен в очередь только один job.
    Queue::assertPushed(PrepareDocumentJob::class, 1);
});

test('dispatchBacklog добирает downloaded-документы по всем закупкам (поллер-страховка)', function () {
    Queue::fake();
    $procurementA = Procurement::factory()->create();
    $procurementB = Procurement::factory()->create();
    $documentA = Document::factory()->create(['procurement_id' => $procurementA->id, 'status' => DocumentStatus::Downloaded]);
    $documentB = Document::factory()->create(['procurement_id' => $procurementB->id, 'status' => DocumentStatus::Downloaded]);
    Document::factory()->create(['procurement_id' => $procurementA->id, 'status' => DocumentStatus::Prepared]);

    $count = app(PipelineDispatcher::class)->dispatchBacklog(limit: 100);

    expect($count)->toBe(2);
    Queue::assertPushed(PrepareDocumentJob::class, fn (PrepareDocumentJob $job) => $job->documentId === $documentA->id);
    Queue::assertPushed(PrepareDocumentJob::class, fn (PrepareDocumentJob $job) => $job->documentId === $documentB->id);
    Queue::assertPushed(PrepareDocumentJob::class, 2);
});

test('dispatchBacklog уважает лимит', function () {
    Queue::fake();
    $procurement = Procurement::factory()->create();
    Document::factory()->count(3)->create(['procurement_id' => $procurement->id, 'status' => DocumentStatus::Downloaded]);

    $count = app(PipelineDispatcher::class)->dispatchBacklog(limit: 2);

    expect($count)->toBe(2);
    Queue::assertPushed(PrepareDocumentJob::class, 2);
});
