<?php

declare(strict_types=1);

namespace App\Services\Http\Proxy;

use InvalidArgumentException;

final class ProxyFactory
{
    public static function make(string $type): Drivers\ProxyDriverInterface
    {
        return match ($type) {
            'database' => new Drivers\DatabaseDriver(),
            'config' => new Drivers\ConfigDriver(),
            default => throw new InvalidArgumentException("Driver {$type} not found"),
        };
    }
}
