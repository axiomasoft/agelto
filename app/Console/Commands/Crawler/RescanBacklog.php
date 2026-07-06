<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Services\Crawler\Modes\BacklogRescanProcessor;
use Illuminate\Console\Command;

/**
 * Тонкий делегат sweep-потребителя `cap_hit` (`02-crawling.md` §4.1) — единственная
 * точка входа backlog-рескана, планируется в `routes/console.php`.
 */
final class RescanBacklog extends Command
{
    protected $signature = 'crawler:rescan-backlog';

    protected $description = 'Разгребает fresh-прогоны с флагом cap_hit точечным archive-рескан окна простоя';

    public function handle(BacklogRescanProcessor $processor): int
    {
        $processed = $processor->sweep();

        $this->info("Backlog-рескан: обработано прогонов — {$processed}.");

        return self::SUCCESS;
    }
}
