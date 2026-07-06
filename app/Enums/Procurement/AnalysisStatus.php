<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Состояние сессии AI-анализа: pending → running → succeeded/failed/skipped.
 */
enum AnalysisStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
