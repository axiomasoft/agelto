<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Crawler;

use App\Enums\Procurement\Crawler\SourceMethod;
use RuntimeException;

final class PlatformAdapterUnavailableException extends RuntimeException
{
    public static function forPlatform(string $platformCode): self
    {
        return new self("Для площадки «{$platformCode}» не настроен runtime adapter в config('crawler.platforms').");
    }

    public static function forSourceMethod(string $platformCode, SourceMethod $method): self
    {
        return new self("Для площадки «{$platformCode}» не настроен source method «{$method->value}».");
    }

    public static function forRegistry(string $platformCode, string $registryCode): self
    {
        return new self("Для реестра «{$registryCode}» площадки «{$platformCode}» не настроен typed filter.");
    }

    public static function forPlatformRelation(string $registryCode): self
    {
        return new self("Реестр «{$registryCode}» не связан с существующей площадкой; typed adapter не определить.");
    }
}
