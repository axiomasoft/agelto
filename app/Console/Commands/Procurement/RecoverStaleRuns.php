<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Jobs\Procurement\CrawlRunJob;
use App\Services\Procurement\CrawlerRunManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Тонкая точка входа подъёма зависших прогонов (P3.2, D28): `CrawlerRunManager::recoverStale()`
 * был реализован и покрыт тестом, но НИКЕМ не вызывался — павший воркер оставлял прогон в
 * `running` навсегда, а частичный unique-индекс превращал его в блокировщик профиля.
 * Регистрация в расписании — `routes/console.php`.
 */
#[Description('Переводит прогоны с протухшим heartbeat из running в paused (resumable)')]
#[Signature('crawler:recover-stale')]
final class RecoverStaleRuns extends Command
{
    public function handle(CrawlerRunManager $runs): int
    {
        $recovered = $runs->recoverStale();
        $dispatched = 0;

        foreach ($runs->recoverableArchiveRuns() as $run) {
            $context = $run->stats?->get('execution_context');

            if (! is_array($context) || ! is_string($context['from'] ?? null) || ! is_string($context['to'] ?? null)) {
                continue;
            }

            $lane = $context['execution_lane'] ?? null;
            $executionLane = is_string($lane) ? CrawlerExecutionLane::tryFrom($lane) : null;

            if ($executionLane === null) {
                continue;
            }

            CrawlRunJob::dispatch(
                crawlerId: $run->crawler_id,
                mode: CrawlerMode::Archive,
                executionLane: $executionLane,
                from: $context['from'],
                to: $context['to'],
            );
            $dispatched++;
        }

        $this->info("Поднято зависших прогонов: {$recovered}.");
        $this->info("Поставлено archive-продолжений: {$dispatched}.");

        return self::SUCCESS;
    }
}
