<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceCircuit;
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
 * delta/backlog продолжатся по своему расписанию. Метку снимает сам процессор после
 * получения аренды и допуска источника, а не отправитель job.
 */
final readonly class SuspendedRunResumer
{
    public function __construct(
        private SourceCircuitRepository $circuits,
        private SourceCircuitBreaker $breaker,
    ) {}

    /** Этапы, чьё ожидание истекло (без ручной паузы). */
    public function resumeDue(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $resumed = 0;

        foreach ($this->circuits->waitingExpired($now) as $circuit) {
            $this->breaker->finishWaiting($circuit);
        }

        // Ищем также уже открытые этапы: предыдущий тик мог упасть после снятия retry_at,
        // но до отправки job. Метка на прогоне сохраняется до фактического допуска воркера.
        foreach (SourceCircuit::query()
            ->where('stage', SourceStage::Listing)
            ->where('manually_paused', false)
            ->whereNull('held_at')
            ->where(fn (Builder $query) => $query->whereNull('retry_at')->orWhere('retry_at', '<=', $now))
            ->lazyById(100) as $circuit) {
            $resumed += $this->resumePlatform(platformId: $circuit->platform_id, actor: SourceCircuitBreaker::SystemActor);
        }

        return $resumed;
    }

    /** Поднять приостановленные прогоны площадки. Возвращает число поставленных job. */
    public function resumePlatform(int $platformId, string $actor): int
    {
        if (! $this->breaker->admits($platformId, SourceStage::Listing)) {
            return 0;
        }

        $runs = Run::query()
            ->where('status', RunStatus::Paused)
            ->where('stats->'.SourceRunControl::StatsFlag, true)
            ->whereHas('crawler.registry', fn (Builder $query) => $query->where('platform_id', $platformId))
            ->whereHas('crawler', fn (Builder $query) => $query->where('is_active', true))
            ->where('purpose', RunPurpose::Profile);

        if ($actor === SourceCircuitBreaker::SystemActor) {
            $runs->where(fn (Builder $query) => $query->whereNull('stats->source_retry_at')
                ->orWhereRaw("(stats->>'source_retry_at')::timestamptz <= ?", [now()]));
        }

        $runs = $runs->orderBy('id')->limit(100)->get();

        $dispatched = 0;

        foreach ($runs as $run) {
            if ($actor !== SourceCircuitBreaker::SystemActor) {
                Run::query()->whereKey($run->id)->where('status', RunStatus::Paused)
                    ->update(['stats->source_retry_at' => null]);
            }

            if (! $this->dispatch(run: $run)) {
                continue;
            }

            $dispatched++;
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
