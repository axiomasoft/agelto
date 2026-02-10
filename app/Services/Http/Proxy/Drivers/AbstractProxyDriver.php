<?php

declare(strict_types=1);

namespace App\Services\Http\Proxy\Drivers;

abstract class AbstractProxyDriver implements ProxyDriverInterface
{
    protected function getRandomUA(): string
    {
        $agents = config('sys.proxies.user_agents', ['Laravel/HTTP-Client']);

        return $agents[array_rand($agents)];
    }
}
