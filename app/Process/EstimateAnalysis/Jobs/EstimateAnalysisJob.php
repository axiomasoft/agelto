<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Process\EstimateAnalysis\EstimateAnalysisProcessor;

final class EstimateAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'platform_estimate_analysis';

    public function __construct(
        public int $documentAnalysisId,
    ) {}

    public function tags(): array
    {
        return ['analysis:' . $this->documentAnalysisId];
    }

    public function handle(EstimateAnalysisProcessor $estimateAnalysisProcessor): void
    {
        $documentAnalysis = DocumentAnalysis::findOrFail($this->documentAnalysisId);
    }
}
