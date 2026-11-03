<?php

declare(strict_types=1);

namespace App\Services\Procurement\Exceptions;

use App\Enums\Procurement\Crawler\SourceMethod;
use RuntimeException;

final class SourceMethodUnavailableException extends RuntimeException
{
    public static function forCrawler(SourceMethod $method, ?SourceMethod $fallback): self
    {
        $fallbackLabel = $fallback === null ? 'none' : $fallback->value;

        return new self(
            message: "Source method [{$method->value}] is unavailable and fallback [{$fallbackLabel}] is not usable.",
        );
    }

    public static function forUnconfiguredMethod(SourceMethod $method): self
    {
        return new self(
            message: "Source method [{$method->value}] has no class mapped in PlatformRegistry definitions.",
        );
    }
}
