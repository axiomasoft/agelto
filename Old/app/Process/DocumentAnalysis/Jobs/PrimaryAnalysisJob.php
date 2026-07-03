<?php

declare(strict_types=1);

namespace App\Process\DocumentAnalysis\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Notifications\PipelineAlert;
use App\Process\DocumentAnalysis\PrimaryAnalysisProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PrimaryAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 180;

    public array $backoff = [30, 60, 300];

    public function __construct(
        public int $documentAnalysisId,
    ) {
        $this->onQueue('platform_primary_analysis');
    }

    public function tags(): array
    {
        return ['analysis:'.$this->documentAnalysisId];
    }

    public function handle(PrimaryAnalysisProcessor $primaryAnalysisProcessor): void
    {
        $documentAnalysis = DocumentAnalysis::findOrFail($this->documentAnalysisId);

        if (in_array($documentAnalysis->status, [
            AnalysisStatus::PrimaryAnalyzed,
            AnalysisStatus::PrimaryIgnored,
            AnalysisStatus::Completed,
        ], strict: true)) {
            return;
        }

        // Лиза против дублирующего запроса к Gemini при крашах/повторах.
        if (! $documentAnalysis->acquireLease(self::class)) {
            return;
        }

        try {
            $primaryAnalysisProcessor->analysis($documentAnalysis);
        } finally {
            $documentAnalysis->releaseLease();
        }
    }

    /**
     * Выполнится, если Job упадет
     */
    public function failed(Throwable $exception): void
    {
        $analysis = DocumentAnalysis::find($this->documentAnalysisId);
        $analysis?->recordFailure(
            status: AnalysisStatus::PrimaryAnalysisFailed,
            message: 'Ошибка первичного анализа: '.$exception->getMessage(),
            trace: $exception->getTraceAsString(),
        );

        PipelineAlert::failure(
            stage: 'Primary Analysis',
            jobClass: self::class,
            jobId: $this->documentAnalysisId,
            message: $exception->getMessage(),
            attempt: $this->attempts(),
            maxAttempts: $this->tries,
        );
    }
}
