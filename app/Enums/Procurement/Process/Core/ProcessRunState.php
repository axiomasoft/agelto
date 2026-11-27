<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Core;

/**
 * Derived состояние конечного run. Не второй source execution state: хранится
 * только authoritative work/run rows, это значение вычисляет `RunStateReducer`.
 */
enum ProcessRunState: string
{
    case Requested = 'requested';
    case Running = 'running';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case CompletedWithIssues = 'completed_with_issues';
    case Cancelled = 'cancelled';

    public function terminal(): bool
    {
        return match ($this) {
            self::Completed, self::CompletedWithIssues, self::Cancelled => true,
            self::Requested, self::Running, self::Waiting => false,
        };
    }
}
