<?php

declare(strict_types=1);

namespace App\Enums\Crawler;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CrawlerRunStatus: string implements HasColor, HasLabel
{
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Running => 'Выполняется',
            self::Paused => 'Пауза',
            self::Completed => 'Завершён',
            self::Failed => 'Ошибка',
            self::Cancelled => 'Отменён',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Running => 'success',
            self::Paused => 'warning',
            self::Completed => 'gray',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /** Прогон ещё «живой» — занимает инвариант единственного активного прогона на профиль. */
    public function isActive(): bool
    {
        return $this === self::Running;
    }

    /** Терминальное состояние — прогон больше не движется. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
