<?php

declare(strict_types=1);

use Illuminate\Support\Collection;

if (! function_exists('platform_config')) {
    /**
     * Конфиг платформы по коду (например, Platform::EIS->value).
     * Возвращает коллекцию, можно использовать ->get('url'), ->get('name') и т.д.
     *
     * @return Collection<string, mixed>
     */
    function platform_config(string $platformCode): Collection
    {
        $config = config('app.platforms.' . $platformCode);

        if ($config === null || $config === []) {
            throw new \InvalidArgumentException("Platform config not found for code: {$platformCode}");
        }

        return collect($config);
    }
}
