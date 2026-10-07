<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SourceIncidentSeverity: string implements HasColor, HasLabel
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function getLabel(): string
    {
        return match ($this) {
            self::Info => 'Информация',
            self::Warning => 'Предупреждение',
            self::Critical => 'Критично',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Info => 'gray',
            self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }
}
