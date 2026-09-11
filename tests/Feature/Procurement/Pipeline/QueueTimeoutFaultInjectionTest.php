<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Jobs\Procurement\PipelineJob;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use Illuminate\Queue\Attributes\Timeout;

test('pipeline jobs carry their bounded worker timeout and the OCR job uses its isolated connection', function (): void {
    $prepare = new PrepareDocumentJob(documentId: 1);
    $screening = new ScreeningJob(documentId: 1);
    $extraction = new EstimateExtractionJob(documentId: 1);

    expect(pipelineJobTimeout(job: $prepare))->toBe(config('ai.pipeline.operation_budgets.prepare.job_timeout_seconds'))
        ->and($prepare->connection)->toBe('redis_prepare')
        ->and($prepare->queue)->toBe('doc_prepare')
        ->and(pipelineJobTimeout(job: $screening))->toBe(config('ai.pipeline.operation_budgets.screening.job_timeout_seconds'))
        ->and(pipelineJobTimeout(job: $extraction))->toBe(config('ai.pipeline.operation_budgets.extraction.job_timeout_seconds'))
        ->and(pipelineJobLeaseSeconds(job: $prepare))->toBe(config('ai.pipeline.operation_budgets.prepare.lease_seconds'))
        ->and(pipelineJobLeaseSeconds(job: $screening))->toBe(config('ai.pipeline.operation_budgets.screening.lease_seconds'))
        ->and(pipelineJobLeaseSeconds(job: $extraction))->toBe(config('ai.pipeline.operation_budgets.extraction.lease_seconds'));
});

test('a live pipeline lease prevents concurrent redelivery only for its own configured active slice', function (): void {
    $document = Document::factory()->create();
    $screening = Analysis::factory()->create(['step' => AnalysisStep::Screening]);
    $extraction = Analysis::factory()->create(['step' => AnalysisStep::Extraction]);
    $prepareLeaseSeconds = (int) config('ai.pipeline.operation_budgets.prepare.lease_seconds');
    $screeningLeaseSeconds = (int) config('ai.pipeline.operation_budgets.screening.lease_seconds');
    $extractionLeaseSeconds = (int) config('ai.pipeline.operation_budgets.extraction.lease_seconds');

    expect($document->acquireLease(owner: 'sigterm-worker', ttlSeconds: $prepareLeaseSeconds))->toBeTrue()
        ->and($document->fresh()->acquireLease(owner: 'redelivered-worker', ttlSeconds: $prepareLeaseSeconds))->toBeFalse()
        ->and($screening->acquireLease(owner: 'sigkill-worker', ttlSeconds: $screeningLeaseSeconds))->toBeTrue()
        ->and($screening->fresh()->acquireLease(owner: 'recovery-worker', ttlSeconds: $screeningLeaseSeconds))->toBeFalse()
        ->and($extraction->acquireLease(owner: 'checkpoint-worker', ttlSeconds: $extractionLeaseSeconds))->toBeTrue()
        ->and($extraction->fresh()->acquireLease(owner: 'redelivered-worker', ttlSeconds: $extractionLeaseSeconds))->toBeFalse();
});

function pipelineJobLeaseSeconds(PipelineJob $job): int
{
    return (int) (new ReflectionMethod($job, 'leaseSeconds'))->invoke($job);
}

function pipelineJobTimeout(PipelineJob $job): int
{
    $attributes = (new ReflectionClass($job))->getAttributes(Timeout::class);

    if (count($attributes) !== 1) {
        throw new RuntimeException('У '.get_class($job).' должен быть ровно один #[Timeout].');
    }

    return $attributes[0]->newInstance()->timeout;
}
