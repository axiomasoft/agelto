<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Crawler;

use RuntimeException;

final class OutboundRequestDeadlineExceeded extends RuntimeException
{
    public static function forStage(string $stage): self
    {
        return new self("Outbound request deadline exhausted before {$stage} could complete.");
    }
}
