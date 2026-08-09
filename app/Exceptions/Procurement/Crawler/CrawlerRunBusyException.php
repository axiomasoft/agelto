<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Crawler;

use App\Models\Procurement\Crawler\Run;
use RuntimeException;

/**
 * Запрошенный owner не может продолжить уже running-прогон другого owner.
 */
final class CrawlerRunBusyException extends RuntimeException
{
    private function __construct(public readonly Run $activeRun)
    {
        parent::__construct("Crawler run {$activeRun->id} is owned by another operation.");
    }

    public static function forRun(Run $activeRun): self
    {
        return new self(activeRun: $activeRun);
    }
}
