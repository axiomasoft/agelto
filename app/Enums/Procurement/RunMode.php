<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Режим запуска прогона (`AnalysisRun`, §3.2 root/document-contour.md): `auto` — боевой
 * конвейер от краулера; `manual` — оператор/harness (ретрай, бенчмарк, eval, кнопка «Прогнать»).
 */
enum RunMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
