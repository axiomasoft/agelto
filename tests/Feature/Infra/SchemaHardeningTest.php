<?php

declare(strict_types=1);

use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementRevision;
use App\Models\Procurement\PurchaseItem;
use Illuminate\Database\QueryException;

test('расширенная точность prc_items.quantity сохраняет и читает шесть знаков после запятой', function () {
    $item = PurchaseItem::factory()->create(['quantity' => '123.456789']);

    expect($item->refresh()->quantity)->toBe('123.456789');
});

test('ON DELETE RESTRICT запрещает удаление закупки с аудитным документом', function () {
    $procurement = Procurement::factory()->create();
    Document::factory()->create(['procurement_id' => $procurement->id]);

    expect(fn () => $procurement->delete())->toThrow(QueryException::class);
});

test('FK prc_revisions.crawler_run_id запрещает удаление прогона, на который ссылается ревизия', function () {
    $run = Run::factory()->create();
    ProcurementRevision::factory()->create(['crawler_run_id' => $run->id]);

    expect(fn () => $run->delete())->toThrow(QueryException::class);
});

test('FK llm_calls.analysis_run_id запрещает удаление прогона анализа, на который ссылается вызов', function () {
    $analysisRun = AnalysisRun::factory()->create();
    LlmCall::factory()->create(['analysis_run_id' => $analysisRun->id]);

    expect(fn () => $analysisRun->delete())->toThrow(QueryException::class);
});

test('предсозданные исторические партиции принимают событие с occurred_at в прошлом', function () {
    $procurement = Procurement::factory()->create();

    $event = ProcurementEvent::factory()->for($procurement, 'procurement')->create([
        'occurred_at' => '2025-09-15 10:00:00',
    ]);

    expect($event->refresh()->occurred_at->toDateString())->toBe('2025-09-15');
});
