<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\Crawling\CrawlingStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PipelineStatusCommand extends Command
{
    protected $signature = 'app:pipeline:status';

    protected $description = 'Показывает текущее состояние пайплайна парсинга по всем стадиям';

    public function handle(): int
    {
        $this->info('Пайплайн ЕИС — состояние на '.now()->format('d.m.Y H:i:s'));
        $this->newLine();

        $this->printLinksStatus();
        $this->newLine();
        $this->printAnalysisStatus();

        return self::SUCCESS;
    }

    private function printLinksStatus(): void
    {
        $this->line('<fg=yellow>Stage 1 — Crawler Links (plt_crawler_links)</>');

        $rows = DB::table('plt_crawler_links')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->keyBy('status');

        $statuses = [
            CrawlingStatus::PENDING->value => 'Pending (ждут обработки)',
            CrawlingStatus::PROCESSING->value => 'Processing (в очереди)',
            CrawlingStatus::COMPLETED->value => 'Completed',
            CrawlingStatus::FAILED->value => 'Failed (ошибка)',
        ];

        $tableRows = [];
        foreach ($statuses as $value => $label) {
            $count = $rows->get($value)?->total ?? 0;
            $tableRows[] = [$label, $count];
        }

        $this->table(['Статус', 'Количество'], $tableRows);

        $failedWithRetry = DB::table('plt_crawler_links')
            ->where('status', CrawlingStatus::FAILED->value)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->count();

        if ($failedWithRetry > 0) {
            $this->warn("  ⚠  Готовы к повторной обработке: {$failedWithRetry} ссылок");
        }
    }

    private function printAnalysisStatus(): void
    {
        $this->line('<fg=yellow>Stage 2–4 — Document Analysis (plt_document_analysis)</>');

        $rows = DB::table('plt_document_analysis')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->keyBy('status');

        $groups = [
            'Stage 2 — Document Prepare' => [
                AnalysisStatus::Preparing->value => 'Preparing',
                AnalysisStatus::Prepared->value => 'Prepared',
            ],
            'Stage 3 — Primary Analysis' => [
                AnalysisStatus::PrimaryAnalyzing->value => 'Analyzing',
                AnalysisStatus::PrimaryAnalyzed->value => 'Analyzed',
                AnalysisStatus::PrimaryIgnored->value => 'Ignored (не смета)',
                AnalysisStatus::PrimaryAnalysisFailed->value => 'Failed',
            ],
            'Stage 4 — Estimate Analysis' => [
                AnalysisStatus::EstimateAnalyzing->value => 'Analyzing',
                AnalysisStatus::EstimateAnalyzed->value => 'Analyzed',
                AnalysisStatus::EstimateAnalysisFailed->value => 'Failed',
                AnalysisStatus::Completed->value => 'Completed ✓',
            ],
            'Ошибки' => [
                AnalysisStatus::Error->value => 'Error (финальная ошибка)',
            ],
        ];

        $tableRows = [];
        foreach ($groups as $group => $statuses) {
            $tableRows[] = ["<fg=cyan>{$group}</>", ''];
            foreach ($statuses as $value => $label) {
                $count = $rows->get($value)?->total ?? 0;
                $tableRows[] = ["  {$label}", $count];
            }
        }

        $this->table(['Стадия / Статус', 'Количество'], $tableRows);
    }
}
