<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\PipelineFailedNotification;
use App\Process\EstimateAnalysis\EstimateAnalysisProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
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

        $estimateAnalysisProcessor->run($documentAnalysis);
    }

    public function failed(Throwable $exception): void
    {
        DocumentAnalysis::find($this->documentAnalysisId)?->transitionStatus(
            status: AnalysisStatus::Error,
            message: 'Ошибка анализа сметы: '.$exception->getMessage(),
            trace: $exception->getTraceAsString(),
        );

        Notification::route(TelegramChannel::class, null)
            ->notify(new PipelineFailedNotification(
                stage: 'Estimate Analysis',
                jobClass: self::class,
                jobId: $this->documentAnalysisId,
                errorMessage: $exception->getMessage(),
                attempt: $this->attempts(),
                maxAttempts: $this->tries,
            ));
    }
}
