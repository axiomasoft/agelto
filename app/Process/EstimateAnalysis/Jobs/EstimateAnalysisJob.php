<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Process\EstimateAnalysis\EstimateAnalysisProcessor;
use Throwable;

final class EstimateAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [60, 300];

    public function __construct(
        public int $documentAnalysisId,
    ) {
        $this->onQueue('platform_estimate_analysis');
    }

    public function tags(): array
    {
        return ['analysis:' . $this->documentAnalysisId];
    }

    public function handle(EstimateAnalysisProcessor $estimateAnalysisProcessor): void
    {
        $documentAnalysis = DocumentAnalysis::findOrFail($this->documentAnalysisId);
        $estimateAnalysisProcessor->run($documentAnalysis);
    }

    public function failed(Throwable $exception): void
    {
        DocumentAnalysis::find($this->documentAnalysisId)?->transitionStatus(
            status: AnalysisStatus::Error,
            message: 'Ошибка анализа сметы: ' . $exception->getMessage(),
            trace: $exception->getTraceAsString(),
        );
    }
}
