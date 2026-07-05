<?php

declare(strict_types=1);

namespace App\Enums;

enum Platform: string
{
    case EIS = 'eis';

    case Local = 'local';

    public function getLabel(): string
    {
        return match ($this) {
            self::EIS => 'ГосЗакупки',
            self::Local => 'Локальная',
        };
    }
}
