<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\Crawler\CrawlerRunStatus;
use App\Enums\Crawling\CrawlingStatus;
use App\Models\Crawler\CrawlerRun;
use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\PipelineAlertNotification;
use App\Process\Crawling\Run\CrawlerRunManager;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class PipelineStuckCheckCommand extends Command
{
    private const ANALYSIS_STUCK_MINUTES = 30;

    private const LINK_STUCK_MINUTES = 60;

    // Вложения без анализа на PENDING-ссылках должны разбираться конвейером за минуты.
    // Если висят дольше — сломан мост Стадия 1 → 2.
    private const BACKLOG_MINUTES = 15;

    // Running-прогон краулера без свежего heartbeat — завис браузер/процесс убит.
    private const CRAWLER_STUCK_MINUTES = CrawlerRunManager::HEARTBEAT_STALE_MINUTES;

    // Очереди, чей backlog в Redis сигналит о заторе.
    private const WATCHED_QUEUES = [
        'default',
        'platform_document_prepare',
        'platform_primary_analysis',
        'platform_estimate_analysis',
    ];

    private const QUEUE_DEPTH_LIMIT = 5000;

    // Chromium валится, если /dev/shm переполнен; алертим при остатке меньше 32MB.
    private const SHM_MIN_FREE_BYTES = 32 * 1024 * 1024;

    protected $signature = 'app:pipeline:stuck-check';

    protected $description = 'Проверяет зависшие задачи в пайплайне и отправляет Telegram-алерт';

    public function handle(): int
    {
        $stuckAnalyses = $this->findStuckAnalyses();
        $stuckLinks = $this->findStuckLinks();
        $backlog = $this->findBacklogAttachments();
        $stuckCrawlers = $this->findStuckCrawlers();
        $infra = $this->findInfraIssues();

        if ($stuckAnalyses === 0 && $stuckLinks === 0 && $backlog === 0 && $stuckCrawlers === 0 && $infra === []) {
            $this->info('Зависших задач не обнаружено.');

            return self::SUCCESS;
        }

        $this->reportToTelegram(
            stuckAnalyses: $stuckAnalyses,
            stuckLinks: $stuckLinks,
            backlog: $backlog,
            stuckCrawlers: $stuckCrawlers,
            infra: $infra,
        );

        $this->warn("Обнаружено: анализов={$stuckAnalyses}, ссылок={$stuckLinks}, backlog-вложений={$backlog}, краулеров={$stuckCrawlers}, инфра=".count($infra));

        return self::SUCCESS;
    }

    /**
     * Инфраструктурные симптомы, невидимые по сущностям БД: PostgreSQL/Redis недоступны,
     * затор очередей, переполнение /dev/shm. Очереди/Redis проверяем только если они реально на redis.
     *
     * @return list<string>
     */
    private function findInfraIssues(): array
    {
        $issues = [];

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $issues[] = 'PostgreSQL недоступен: '.$e->getMessage();
        }

        if (config('queue.default') === 'redis') {
            try {
                $redis = Redis::connection();
                $redis->ping();

                foreach (self::WATCHED_QUEUES as $queue) {
                    $depth = (int) $redis->llen('queues:'.$queue);
                    if ($depth > self::QUEUE_DEPTH_LIMIT) {
                        $issues[] = "Очередь {$queue}: backlog {$depth} (> ".self::QUEUE_DEPTH_LIMIT.')';
                    }
                }
            } catch (Throwable $e) {
                $issues[] = 'Redis недоступен: '.$e->getMessage();
            }
        }

        if (is_dir('/dev/shm')) {
            $free = @disk_free_space('/dev/shm');
            if ($free !== false && $free < self::SHM_MIN_FREE_BYTES) {
                $issues[] = '/dev/shm почти заполнен: свободно '.round($free / 1048576).'MB';
            }
        }

        return $issues;
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

    /**
     * Активные прогоны краулера без свежего heartbeat — браузер завис или процесс убит.
     * Команда краулера запускается вручную и не перезапускается Docker'ом, поэтому именно
     * планировщик (этот stuck-check) сигналит о «мёртвом» прогоне.
     */
    private function findStuckCrawlers(): int
    {
        return CrawlerRun::query()
            ->where('status', CrawlerRunStatus::Running)
            ->whereRaw(
                'COALESCE(last_heartbeat_at, started_at) <= ?',
                [Carbon::now()->subMinutes(self::CRAWLER_STUCK_MINUTES)],
            )
            ->count();
    }

    /**
     * @param  list<string>  $infra
     */
    private function reportToTelegram(int $stuckAnalyses, int $stuckLinks, int $backlog, int $stuckCrawlers, array $infra = []): void
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

        if ($stuckCrawlers > 0) {
            $parts[] = 'Зависших прогонов краулера (>'.self::CRAWLER_STUCK_MINUTES."мин, heartbeat): <b>{$stuckCrawlers}</b>";
        }

        foreach ($infra as $issue) {
            $parts[] = '🔧 '.$issue;
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
