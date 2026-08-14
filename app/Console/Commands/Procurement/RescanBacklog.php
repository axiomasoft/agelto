<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Services\Procurement\Modes\BacklogRescanProcessor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Тонкий делегат sweep-потребителя `cap_hit` (`02-crawling.md` §4.1) — единственная
 * точка входа backlog-рескана, планируется в `routes/console.php`.
 */
#[Description('Разгребает fresh-прогоны с флагом cap_hit точечным archive-рескан окна простоя')]
#[Signature('crawler:rescan-backlog')]
final class RescanBacklog extends Command
{
    public function handle(BacklogRescanProcessor $processor): int
    {
        $processed = $processor->sweep();

        $this->info("Backlog-рескан: обработано прогонов — {$processed}.");

        return self::SUCCESS;
    }
}
