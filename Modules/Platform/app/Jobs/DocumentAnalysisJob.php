<?php

declare(strict_types=1);

namespace Platform\Jobs;

use App\Services\AppLogger;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Platform\Process\DocumentAnalysis\PrimaryAnalysisProcessor;
use Platform\Process\DocumentPrepare\DocumentProcessor;
use Platform\Repositories\Promt\PromptRuleRepository;

final class DocumentAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public DocumentAnalysis $documentAnalysis,
    ) {}

    public function handle(): void
    {
        $primaryAnalysisProcessor = new PrimaryAnalysisProcessor(
            gemini: app(GeminiService::class),
            promptRepo: app(PromptRuleRepository::class),
            documentProcessor: app(DocumentProcessor::class),
            logger: app(AppLogger::class),
        );
        $primaryAnalysisProcessor->run($this->documentAnalysis);
    }
}
