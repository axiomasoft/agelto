<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Crawler;

use RuntimeException;

final class PlatformBudgetExhaustedException extends RuntimeException
{
    public static function forHost(string $host, int $maxWaitSeconds): self
    {
        return new self("Бюджет запросов к {$host} не выдал слот за {$maxWaitSeconds} сек.");
    }
}
