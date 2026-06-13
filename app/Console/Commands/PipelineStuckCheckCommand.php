<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\Crawling\CrawlingStatus;
use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\PipelineAlertNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

final class PipelineStuckCheckCommand extends Command
{
    private const ANALYSIS_STUCK_MINUTES = 30;

    private const LINK_STUCK_MINUTES = 60;

    // Вложения без анализа на PENDING-ссылках должны разбираться конвейером за минуты.
    // Если висят дольше — сломан мост Стадия 1 → 2.
    private const BACKLOG_MINUTES = 15;

    protected $signature = 'app:pipeline:stuck-check';

    protected $description = 'Проверяет зависшие задачи в пайплайне и отправляет Telegram-алерт';

    public function handle(): int
    {
        $stuckAnalyses = $this->findStuckAnalyses();
        $stuckLinks = $this->findStuckLinks();
        $backlog = $this->findBacklogAttachments();

        if ($stuckAnalyses === 0 && $stuckLinks === 0 && $backlog === 0) {
            $this->info('Зависших задач не обнаружено.');

            return self::SUCCESS;
        }

        $this->reportToTelegram(stuckAnalyses: $stuckAnalyses, stuckLinks: $stuckLinks, backlog: $backlog);

        $this->warn("Обнаружено: анализов={$stuckAnalyses}, ссылок={$stuckLinks}, backlog-вложений={$backlog}");

        return self::SUCCESS;
    }

    private function findStuckAnalyses(): int
    {
        $stuckStatuses = [
            AnalysisStatus::Preparing->value,
            AnalysisStatus::PrimaryAnalyzing->value,
            AnalysisStatus::EstimateAnalyzing->value,
        ];

        return DocumentAnalysis::query()
            ->whereIn('status', $stuckStatuses)
            ->where(column: 'updated_at', operator: '<=', value: Carbon::now()->subMinutes(self::ANALYSIS_STUCK_MINUTES))
            ->count();
    }

    private function findStuckLinks(): int
    {
        return Link::query()
            ->where('status', CrawlingStatus::PROCESSING)
            ->where(column: 'updated_at', operator: '<=', value: Carbon::now()->subMinutes(self::LINK_STUCK_MINUTES))
            ->count();
    }

    /**
     * Вложения PENDING-ссылок без анализа, висящие дольше порога — прямой симптом разрыва конвейера Стадия 1 → 2.
     */
    private function findBacklogAttachments(): int
    {
        return LinkAttachment::query()
            ->whereDoesntHave('documentAnalyses')
            ->whereHas('link', fn ($query) => $query->where('status', CrawlingStatus::PENDING))
            ->where(column: 'updated_at', operator: '<=', value: Carbon::now()->subMinutes(self::BACKLOG_MINUTES))
            ->count();
    }

    private function reportToTelegram(int $stuckAnalyses, int $stuckLinks, int $backlog): void
    {
        $parts = [];

        if ($stuckAnalyses > 0) {
            $parts[] = 'Зависших анализов (>'.self::ANALYSIS_STUCK_MINUTES."мин): <b>{$stuckAnalyses}</b>";
        }

        if ($stuckLinks > 0) {
            $parts[] = 'Зависших ссылок (>'.self::LINK_STUCK_MINUTES."мин): <b>{$stuckLinks}</b>";
        }

        if ($backlog > 0) {
            $parts[] = 'Вложений без анализа (>'.self::BACKLOG_MINUTES."мин, конвейер?): <b>{$backlog}</b>";
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
