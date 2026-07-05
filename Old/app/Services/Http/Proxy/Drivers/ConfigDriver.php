<?php

declare(strict_types=1);

namespace App\Services\Http\Proxy\Drivers;

final class ConfigDriver extends AbstractProxyDriver
{
    public function getProxy(): ?array
    {
        $proxies = config('sys.proxies.list', []);
        if (empty($proxies)) {
            return null;
        }

        return [
            'url' => $proxies[array_rand($proxies)],
            'ua' => $this->getRandomUA(),
        ];
    }
}
