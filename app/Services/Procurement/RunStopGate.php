<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\Crawler\RunStopKind;
use App\Models\Procurement\Crawler\Run;

/**
 * Кооперативный стоп-гейт (P3.2, D28): остановить УЖЕ ВЫПОЛНЯЮЩИЙСЯ job средствами
 * Laravel/Horizon нельзя, поэтому процессоры сами читают этот флаг между блоками. Один
 * метод, один смысл — «просили ли остановиться»; решение, ЧТО делать по сигналу
 * (перевод статуса), принимает вызывающий процессор через `CrawlerRunManager`.
 */
final readonly class RunStopGate
{
    public function signal(Run $run): ?RunStopKind
    {
        $run->refresh();

        return $run->stop_requested_at !== null ? $run->stop_kind : null;
    }
}
