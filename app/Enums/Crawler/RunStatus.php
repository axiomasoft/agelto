<?php

declare(strict_types=1);

namespace App\Enums\Crawler;

/**
 * Статус прогона краулера.
 */
enum RunStatus: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
