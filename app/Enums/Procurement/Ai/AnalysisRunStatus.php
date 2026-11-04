<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Ai;

/**
 * Статус прогона (`AnalysisRun`): `pending → running →
 * succeeded|failed`. Без `skipped` — «шаг пропущен» (unprocessable) свойство ШАГА, а не прогона:
 * прогон, дошедший до unprocessable-шага, отработал штатно и терминируется `succeeded`.
 */
enum AnalysisRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
