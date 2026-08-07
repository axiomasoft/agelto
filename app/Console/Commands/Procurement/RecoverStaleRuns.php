<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Services\Procurement\CrawlerRunManager;
use Illuminate\Console\Command;

/**
 * Тонкая точка входа подъёма зависших прогонов (P3.2, D28): `CrawlerRunManager::recoverStale()`
 * был реализован и покрыт тестом, но НИКЕМ не вызывался — павший воркер оставлял прогон в
 * `running` навсегда, а частичный unique-индекс превращал его в блокировщик профиля.
 * Регистрация в расписании — `routes/console.php`.
 */
final class RecoverStaleRuns extends Command
{
    protected $signature = 'crawler:recover-stale';

    protected $description = 'Переводит прогоны с протухшим heartbeat из running в paused (resumable)';

    public function handle(CrawlerRunManager $runs): int
    {
        $recovered = $runs->recoverStale();

        $this->info("Поднято зависших прогонов: {$recovered}.");

        return self::SUCCESS;
    }
}
