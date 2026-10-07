<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Services\Procurement\SourceControl\SuspendedRunResumer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Автоматический повтор после паузы источника: раз в минуту (`routes/console.php`) поднимает
 * прогоны площадок, у которых истёк `retry_at` брейкера. Сам брейкер переводит в half-open
 * первый блок такого прогона (`SourceCircuitController::gate`), а не эта команда, — поэтому
 * повторный запуск команды до пробы безопасен.
 */
#[Description('Возобновляет прогоны источников, у которых истекла пауза брейкера')]
#[Signature('crawler:source-circuits')]
final class TickSourceCircuits extends Command
{
    public function handle(SuspendedRunResumer $resumer): int
    {
        $dispatched = $resumer->resumeDue();

        $this->info("Поставлено возобновлений прогонов: {$dispatched}.");

        return self::SUCCESS;
    }
}
