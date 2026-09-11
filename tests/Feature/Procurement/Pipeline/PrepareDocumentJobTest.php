<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\PipelineRecoveryReconciler;
use Illuminate\Support\Facades\Queue;

test('после commit Prepared без screening dispatch reconciler ставит единственный следующий job', function (): void {
    Queue::fake();
    config(['ai.screening.enabled' => true]);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    AnalysisRun::factory()->create(['document_version_id' => $version->id]);

    expect(app(PipelineRecoveryReconciler::class)->reconcile(limit: 1))->toBe(1);
    Queue::assertPushed(ScreeningJob::class, fn (ScreeningJob $job): bool => $job->documentId === $document->id);
    Queue::assertPushed(ScreeningJob::class, 1);
});
