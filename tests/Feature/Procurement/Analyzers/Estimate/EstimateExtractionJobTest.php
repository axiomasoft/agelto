<?php

declare(strict_types=1);

use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use Illuminate\Support\Facades\Queue;

// Уникальность job'а при конкурентном диспатче (P7.7, research/10 §3, D39): под `Queue::fake()`
// `Illuminate\Foundation\Bus\PendingDispatch` всё равно проверяет `UniqueLock` ДО похода в
// очередь (тот же приём, что `PipelineDispatcherTest` — unique-лок `PrepareDocumentJob`) — второй
// вызов с тем же `uniqueId()` лока не берёт, реально запушен только один job. Критично именно для
// `EstimateExtractionJob` (`ShouldBeUniqueUntilProcessing`): два независимых источника диспатча —
// боевой путь (`ScreeningOutcomeHandler`/`RerunAnalysisAction`) и `pipeline:retry-failed` (Д-4,
// P7.7) — способны столкнуться на одном документе, если лиз уже держит другой воркер.

test('EstimateExtractionJob::dispatch дважды подряд на один documentId пушит в очередь только один job', function (): void {
    Queue::fake();

    EstimateExtractionJob::dispatch(documentId: 1);
    EstimateExtractionJob::dispatch(documentId: 1);

    Queue::assertPushed(EstimateExtractionJob::class, 1);
});

test('EstimateExtractionJob::dispatch на РАЗНЫЕ documentId не делит unique-лок', function (): void {
    Queue::fake();

    EstimateExtractionJob::dispatch(documentId: 1);
    EstimateExtractionJob::dispatch(documentId: 2);

    Queue::assertPushed(EstimateExtractionJob::class, 2);
});

test('uniqueFor EstimateExtractionJob задаёт TTL unique-лока больше TTL лиза (600с) — та же защита от вечного лока, что PrepareDocumentJob (ADR-0009)', function (): void {
    expect(app(EstimateExtractionJob::class, ['documentId' => 1])->uniqueFor())->toBeGreaterThan(600);
});
