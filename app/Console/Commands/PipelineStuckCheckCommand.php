<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\Crawling\CrawlingStatus;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\PipelineAlertNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class PipelineStuckCheckCommand extends Command
{
    private const ANALYSIS_STUCK_MINUTES = 30;

    private const LINK_STUCK_MINUTES = 60;

    protected $signature = 'app:pipeline:stuck-check';

    protected $description = 'Проверяет зависшие задачи в пайплайне и отправляет Telegram-алерт';

    public function handle(): int
    {
        $stuckAnalyses = $this->findStuckAnalyses();
        $stuckLinks = $this->findStuckLinks();

        if ($stuckAnalyses === 0 && $stuckLinks === 0) {
            $this->info('Зависших задач не обнаружено.');

            return self::SUCCESS;
        }

        $this->reportToTelegram(stuckAnalyses: $stuckAnalyses, stuckLinks: $stuckLinks);

        $this->warn("Обнаружено зависших задач: анализов={$stuckAnalyses}, ссылок={$stuckLinks}");

        return self::SUCCESS;
    }

    private function findStuckAnalyses(): int
    {
        $stuckStatuses = [
            AnalysisStatus::Preparing->value,
            AnalysisStatus::PrimaryAnalyzing->value,
            AnalysisStatus::EstimateAnalyzing->value,
        ];

        return DB::table('plt_document_analysis')
            ->whereIn('status', $stuckStatuses)
            ->where('updated_at', '<=', Carbon::now()->subMinutes(self::ANALYSIS_STUCK_MINUTES))
            ->count();
    }

    private function findStuckLinks(): int
    {
        return DB::table('plt_crawler_links')
            ->where('status', CrawlingStatus::PROCESSING->value)
            ->where('updated_at', '<=', Carbon::now()->subMinutes(self::LINK_STUCK_MINUTES))
            ->count();
    }

    private function reportToTelegram(int $stuckAnalyses, int $stuckLinks): void
    {
        $parts = [];

        if ($stuckAnalyses > 0) {
            $parts[] = "Зависших анализов (>{self::ANALYSIS_STUCK_MINUTES}мин): <b>{$stuckAnalyses}</b>";
        }

        if ($stuckLinks > 0) {
            $parts[] = "Зависших ссылок (>{self::LINK_STUCK_MINUTES}мин): <b>{$stuckLinks}</b>";
        }

        $env = config('app.env') !== 'production' ? ' ['.mb_strtoupper((string) config('app.env')).']' : '';
        $message = implode("\n", [
            "⚠️ <b>[AGELTO{$env}] Зависшие задачи в пайплайне</b>",
            '',
            ...$parts,
            '',
            'Проверьте Horizon или запустите <code>php artisan app:pipeline:status</code>',
        ]);

        Notification::route(TelegramChannel::class, null)
            ->notify(new PipelineAlertNotification(message: $message));
    }
}
