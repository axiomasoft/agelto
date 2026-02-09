<?php

namespace Platform\Enums;

enum Platform: string
{
    case EIS = 'eis';

    public function getLabel(): string
    {
        return match ($this) {
            self::EIS => 'ГосЗакупки',
        };
    }
}
