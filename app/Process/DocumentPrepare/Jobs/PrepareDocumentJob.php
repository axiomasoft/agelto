<?php

declare(strict_types=1);

namespace App\Process\DocumentPrepare\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Notifications\PipelineFailedNotification;
use App\Process\DocumentPrepare\DocumentProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class PrepareDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [60, 300];

    public function __construct(
        public int $documentAnalysisId,
    ) {
        $this->onQueue('platform_document_prepare');
    }

    public function tags(): array
    {
        return ['analysis:'.$this->documentAnalysisId];
    }

    public function handle(DocumentProcessor $documentProcessor): void
    {
        $documentAnalysis = DocumentAnalysis::findOrFail($this->documentAnalysisId);

        if ($documentAnalysis->status === AnalysisStatus::Prepared) {
            return;
        }

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

        Notification::route(\App\Notifications\Channels\TelegramChannel::class, null)
            ->notify(new PipelineFailedNotification(
                stage: 'Document Prepare',
                jobClass: self::class,
                jobId: $this->documentAnalysisId,
                errorMessage: $exception->getMessage(),
                attempt: $this->attempts(),
                maxAttempts: $this->tries,
            ));
    }
}
