<?php

declare(strict_types=1);

namespace App\Process\DocumentAnalysis\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Process\DocumentAnalysis\PrimaryAnalysisProcessor;
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
        return ['analysis:' . $this->documentAnalysisId];
    }

    public function handle(PrimaryAnalysisProcessor $primaryAnalysisProcessor): void
    {
        $documentAnalysis = DocumentAnalysis::findOrFail($this->documentAnalysisId);

        // Первичный анализ
        $primaryAnalysisProcessor->analysis($documentAnalysis);
    }

    /**
     * Выполнится, если Job упадет
     */
    public function failed(Throwable $exception): void
    {
        $analysis = DocumentAnalysis::find($this->documentAnalysisId);
        $analysis?->transitionStatus(
            status: AnalysisStatus::Error,
            message: 'Ошибка первичного анализа: ' . $exception->getMessage(),
            trace: $exception->getTraceAsString(),
        );
    }
}
