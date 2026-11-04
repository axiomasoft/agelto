<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Ai;

/**
 * Режим запуска прогона (`AnalysisRun`): `auto` — боевой
 * конвейер от краулера; `manual` — оператор/harness (ретрай, бенчмарк, eval, кнопка «Прогнать»).
 */
enum RunMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
