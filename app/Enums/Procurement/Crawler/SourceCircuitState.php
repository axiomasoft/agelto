<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

/**
 * Состояние брейкера источника (площадки). `Open` — обход источника на паузе до `retry_at`;
 * `HalfOpen` — пробный блок после паузы: успех закрывает, отказ снова открывает.
 */
enum SourceCircuitState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';

    public function allowsRequests(): bool
    {
        return $this !== self::Open;
    }

    public function label(): string
    {
        return match ($this) {
            self::Closed => 'Работает',
            self::Open => 'Пауза (брейкер открыт)',
            self::HalfOpen => 'Пробный запуск',
        };
    }
}
