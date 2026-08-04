<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Ai;

/**
 * Статус прогона (`AnalysisRun`, §3.2 root/document-contour.md): `pending → running →
 * succeeded|failed`. Без `skipped` — «шаг пропущен» (unprocessable) свойство ШАГА, а не прогона:
 * прогон, дошедший до unprocessable-шага, отработал штатно и терминируется `succeeded` (§3.2).
 */
enum AnalysisRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
