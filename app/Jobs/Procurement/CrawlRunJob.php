<?php

declare(strict_types=1);

namespace App\Jobs\Procurement;

use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Crawler\CrawlQueueRoute;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\Modes\ArchiveRunProcessor;
use App\Services\Procurement\Modes\FreshRunProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Прогон краулера на profile-driven execution lane (P3.2, P4.7, D27/D28/D117): вызывает процессор режима и
 * самодиспатчится продолжением, пока процессор выходит по бюджету одного запуска
 * (`crawler.run.checkpoint_seconds`) с сохранённым курсором и статусом `running` — тот
 * же checkpoint-requeue приём, что `EstimateExtractionJob` (D39). `ShouldBeUniqueUntilProcessing`,
 * а НЕ `ShouldBeUnique`: под простым `ShouldBeUnique` self-dispatch продолжения тихо
 * дедуплицировался бы, пока текущий запуск ещё держит lock, и прогон завис бы навсегда.
 * БЕЗ свойств очередного ретрая ($tries/$backoff/retryUntil/failOnTimeout) — ретрай
 * контура Procurement сознательно живёт в домене, а не в очереди (`JobRetryContractGuardTest`);
 * инвариант таймаутов supervisor'а — `checkpoint_seconds (240) < timeout (300) <
 * queue.redis.retry_after (360)` (`config/horizon.php` `supervisor-crawl`), нарушение
 * которого отдаёт job ВТОРОМУ воркеру, пока первый ещё легитимно работает.
 */
final class CrawlRunJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $crawlerId,
        public readonly CrawlerMode $mode,
        public readonly CrawlerExecutionLane $executionLane = CrawlerExecutionLane::Standard,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {
        $route = CrawlQueueRoute::forLane(lane: $this->executionLane);

        $this->onConnection($route->connection)->onQueue($route->queue);
    }

    public function uniqueId(): string
    {
        return (string) $this->crawlerId;
    }

    /**
     * TTL unique-лока: без объявления резолвится в `0` — на Redis `SETNX` без `EXPIRE`
     * держит лок вечно, если воркер, забравший job, падает ДО начала обработки (тот же
     * footgun, что `PipelineJob::uniqueFor()` документирует для пайплайна документов).
     */
    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(
        CrawlerRunManager $runs,
        FreshRunProcessor $freshProcessor,
        ArchiveRunProcessor $archiveProcessor,
    ): void {
        $crawler = Crawler::findOrFail($this->crawlerId);

        $run = $runs->startOrResume(crawler: $crawler, purpose: RunPurpose::Profile, subjectKey: null);
        $run->update(['queue_job_id' => $this->job?->getJobId()]);

        $run = match ($this->mode) {
            CrawlerMode::Fresh => $freshProcessor->run(crawler: $crawler),
            // `LogicException` здесь — инвариант ПОСЛЕДНЕЙ инстанции, не пользовательский отказ:
            // `StartCrawlerRunAction` (P3.8) резолвит окно (override → профиль → отказ ДО
            // диспатча) и никогда не отправляет archive-job без обеих дат — упасть сюда может
            // только прямой вызов `CrawlRunJob::dispatch()` в обход Action.
            CrawlerMode::Archive => $archiveProcessor->run(
                crawler: $crawler,
                from: Carbon::parse($this->from ?? throw new LogicException('CrawlRunJob(mode=Archive) требует $from.')),
                to: Carbon::parse($this->to ?? throw new LogicException('CrawlRunJob(mode=Archive) требует $to.')),
            ),
        };

        if ($run->status === RunStatus::Running) {
            self::dispatch(
                crawlerId: $this->crawlerId,
                mode: $this->mode,
                executionLane: $this->executionLane,
                from: $this->from,
                to: $this->to,
            );
        }
    }
}
