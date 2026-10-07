<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Run;
use App\Repositories\Procurement\Crawler\SourceCircuitRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Подъём прогонов, приостановленных брейкером источника (`stats.source_suspended = true`).
 *
 * Автоматика: `crawler:source-circuits` раз в минуту находит этапы, у которых ожидание
 * закончилось, снимает `retry_at` и поднимает прогоны листинга — они просто продолжаются с
 * того же курсора. Этап карточек прогонов не держит: очередь карточек сама пойдёт снова.
 * Оператор: «Возобновить сейчас» в админке — то же самое сразу, минуя ожидание.
 *
 * Поднимаются только профильные прогоны (`RunPurpose::Profile`, они идут через `CrawlRunJob`);
 * у delta/backlog метка снимается, а сами они продолжатся по своему расписанию — гейт
 * источника не даст им сделать запрос раньше времени.
 */
final readonly class SuspendedRunResumer
{
    public function __construct(
        private SourceCircuitRepository $circuits,
        private SourceCircuitBreaker $breaker,
        private SourceEventReporter $events,
    ) {}

    /** Этапы, чьё ожидание истекло (без ручной паузы). */
    public function resumeDue(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $resumed = 0;

        foreach ($this->circuits->waitingExpired($now) as $circuit) {
            $this->breaker->finishWaiting($circuit);

            if ($circuit->stage === SourceStage::Listing) {
                $resumed += $this->resumePlatform(platformId: $circuit->platform_id, actor: SourceCircuitBreaker::SystemActor);
            }
        }

        return $resumed;
    }

    /** Поднять приостановленные прогоны площадки. Возвращает число поставленных job. */
    public function resumePlatform(int $platformId, string $actor): int
    {
        $runs = Run::query()
            ->where('status', RunStatus::Paused)
            ->where('stats->'.SourceRunControl::StatsFlag, true)
            ->whereHas('crawler.registry', fn (Builder $query) => $query->where('platform_id', $platformId))
            ->orderBy('id')
            ->get();

        $dispatched = 0;

        foreach ($runs as $run) {
            $stats = $run->stats?->toArray() ?? [];
            unset($stats[SourceRunControl::StatsFlag], $stats['source_retry_at']);
            $run->update(['stats' => $stats]);

            if ($run->purpose !== RunPurpose::Profile || ! $this->dispatch(run: $run)) {
                continue;
            }

            $dispatched++;
            $this->events->report(
                code: CrawlerEventCode::RunResumed,
                platformId: $platformId,
                message: "Прогон #{$run->id} возобновлён после паузы источника.",
                crawlerId: $run->crawler_id,
                runId: $run->id,
                actor: $actor,
            );
        }

        return $dispatched;
    }

    private function dispatch(Run $run): bool
    {
        $context = $run->stats?->get('execution_context');

        if (! is_array($context)) {
            return false;
        }

        $mode = is_string($context['mode'] ?? null) ? CrawlerMode::tryFrom($context['mode']) : null;
        $lane = is_string($context['execution_lane'] ?? null) ? CrawlerExecutionLane::tryFrom($context['execution_lane']) : null;

        if ($mode === null || $lane === null) {
            return false;
        }

        CrawlRunJob::dispatch(
            crawlerId: $run->crawler_id,
            mode: $mode,
            executionLane: $lane,
            from: is_string($context['from'] ?? null) ? $context['from'] : null,
            to: is_string($context['to'] ?? null) ? $context['to'] : null,
        );

        return true;
    }
}
