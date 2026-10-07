<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use Carbon\CarbonImmutable;

/**
 * Решение гейта источника перед блоком: обычный запрос, пробный запрос после паузы
 * (переход в half-open) или отказ до `retryAt` (`null` — до ручного возобновления).
 */
final readonly class SourceGateDecision
{
    private function __construct(
        public bool $allowed,
        public bool $probe,
        public ?CarbonImmutable $retryAt,
    ) {}

    public static function allow(): self
    {
        return new self(allowed: true, probe: false, retryAt: null);
    }

    public static function probe(): self
    {
        return new self(allowed: true, probe: true, retryAt: null);
    }

    public static function deny(?CarbonImmutable $retryAt): self
    {
        return new self(allowed: false, probe: false, retryAt: $retryAt);
    }
}
