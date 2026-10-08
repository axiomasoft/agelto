<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SourceIncidentSeverity: string implements HasColor, HasLabel
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    /** Уровень PSR-3 для лога: значения кейсов совпадают с уровнями `Psr\Log\LogLevel`. */
    public function logLevel(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Info => 'Информация',
            self::Warning => 'Предупреждение',
            self::Error => 'Ошибка',
            self::Critical => 'Критично',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Info => 'gray',
            self::Warning => 'warning',
            self::Error => 'danger',
            self::Critical => 'danger',
        };
    }
}
