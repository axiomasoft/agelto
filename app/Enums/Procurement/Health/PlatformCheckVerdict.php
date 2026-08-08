<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Health;

enum PlatformCheckVerdict: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Broken = 'broken';
    case NotEnoughData = 'not_enough_data';

    public function isWorseThan(self $previous): bool
    {
        return $this->severity() > $previous->severity();
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::NotEnoughData => 'gray',
            self::Degraded => 'warning',
            self::Broken => 'danger',
        };
    }

    private function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::NotEnoughData => 1,
            self::Degraded => 2,
            self::Broken => 3,
        };
    }
}
