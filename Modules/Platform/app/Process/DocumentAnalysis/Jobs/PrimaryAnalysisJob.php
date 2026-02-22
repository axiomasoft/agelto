<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Enums\AnalysisStatus;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Platform\Process\DocumentAnalysis\PrimaryAnalysisProcessor;
use Throwable;

final class PrimaryAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'platform_primary_analysis';

    public function __construct(
        public int $documentAnalysisId,
    ) {}

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
