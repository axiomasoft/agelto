<?php

declare(strict_types=1);

namespace Platform\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Process\DocumentAnalysis\PrimaryAnalysisProcessor;

final class DocumentAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public PrimaryAnalysisProcessor $primaryAnalysisProcessor,
    ) {}

    public function handle(): void {}
}
