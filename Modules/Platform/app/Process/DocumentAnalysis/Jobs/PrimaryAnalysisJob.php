<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Process\DocumentAnalysis\PrimaryAnalysisProcessor;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Platform\Enums\AnalysisStatus;
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
        DocumentAnalysis::where('id', $this->documentAnalysisId)->update([
            'status' => AnalysisStatus::Error,
            'last_error' => 'Ошибка первичного анализа: ' . $exception->getMessage()
        ]);
    }
}
