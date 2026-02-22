<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Process\DocumentPrepare\DocumentProcessor;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;

final class PrimaryAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $documentAnalysisId,
    ) {}

    public function handle(DocumentProcessor $documentProcessor): void
    {
        $documentAnalysis = DocumentAnalysis::findOrFail($this->documentAnalysisId);

        $documentProcessor->run($documentAnalysis);
    }
}
