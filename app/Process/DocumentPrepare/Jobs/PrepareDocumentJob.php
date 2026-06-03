<?php

declare(strict_types=1);

namespace App\Process\DocumentPrepare\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Process\DocumentPrepare\DocumentProcessor;
use Throwable;

final class PrepareDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'platform_document_prepare';

    public function __construct(
        public int $documentAnalysisId,
    ) {}

    public function tags(): array
    {
        return ['analysis:'.$this->documentAnalysisId];
    }

    public function handle(DocumentProcessor $documentProcessor): void
    {
        $documentAnalysis = DocumentAnalysis::findOrFail($this->documentAnalysisId);

        $documentProcessor->run($documentAnalysis);
    }

    /**
     * Выполнится, если Job упадет
     */
    public function failed(Throwable $exception): void
    {
        $analysis = DocumentAnalysis::find($this->documentAnalysisId);
        if ($analysis !== null) {
            $analysis->transitionStatus(
                status: AnalysisStatus::Error,
                message: 'Ошибка подготовки: '.$exception->getMessage(),
                trace: $exception->getTraceAsString(),
            );
        }
    }
}
