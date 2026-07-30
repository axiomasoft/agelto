<?php

declare(strict_types=1);

namespace Tests\Support\Analyzer;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job-двойник фиктивного второго типа (P2.6, D14/D26) — доказывает, что ядро диспатчит job
 * АНАЛИЗАТОРА, а не называет его класс само: `ScreeningOutcomeHandler` знает только про
 * `DocumentAnalyzer::dispatchExtraction()`, конкретный job — знание типа, живущее ИСКЛЮЧИТЕЛЬНО
 * здесь (`tests/`), а не в продуктовом коде (Implementation Rule 2 item'а).
 */
final class TechSpecFakeExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $documentId,
    ) {}

    public function handle(): void {}
}
