<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Notifications\PipelineAlert;
use App\Process\EstimateAnalysis\EstimateAnalysisProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        return ['analysis:'.$this->documentAnalysisId];
    }

    public function handle(EstimateAnalysisProcessor $estimateAnalysisProcessor): void
    {
        $documentAnalysis = DocumentAnalysis::findOrFail($this->documentAnalysisId);

        if (in_array($documentAnalysis->status, [
            AnalysisStatus::EstimateAnalyzed,
            AnalysisStatus::Completed,
        ], strict: true)) {
            return;
        }

        // Лиза против дублирующего запроса к Gemini при крашах/повторах.
        if (! $documentAnalysis->acquireLease(self::class)) {
            return;
        }

        try {
            // Помечаем «в анализе» здесь, в воркере, а не до commit транзакции в процессоре.
            $documentAnalysis->transitionStatus(
                status: AnalysisStatus::EstimateAnalyzing,
                message: 'Начало анализа сметы',
            );

            $estimateAnalysisProcessor->run($documentAnalysis);
        } finally {
            $documentAnalysis->releaseLease();
        }
    }

    public function failed(Throwable $exception): void
    {
        DocumentAnalysis::find($this->documentAnalysisId)?->recordFailure(
            status: AnalysisStatus::EstimateAnalysisFailed,
            message: 'Ошибка анализа сметы: '.$exception->getMessage(),
            trace: $exception->getTraceAsString(),
        );

        PipelineAlert::failure(
            stage: 'Estimate Analysis',
            jobClass: self::class,
            jobId: $this->documentAnalysisId,
            message: $exception->getMessage(),
            attempt: $this->attempts(),
            maxAttempts: $this->tries,
        );
    }
}
