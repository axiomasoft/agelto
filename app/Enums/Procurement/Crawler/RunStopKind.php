<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

/**
 * Запрошенный вид кооперативной остановки прогона (P3.2, D28): читается `RunStopGate`
 * из `prc_crawler_runs.stop_kind`, оператор выставляет через админку (P3.4).
 */
enum RunStopKind: string
{
    case Pause = 'pause';
    case Cancel = 'cancel';
}
