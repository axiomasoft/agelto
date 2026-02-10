<?php

declare(strict_types=1);

namespace App\Services\Http\Proxy\Drivers;

use Sys\Models\Proxy\Proxy;

final class DatabaseDriver extends AbstractProxyDriver
{
    public function getProxy(): ?array
    {
        $proxy = Proxy::where('is_active', true)->inRandomOrder()->first();

        return $proxy ? [
            'url' => "http://{$proxy->username}:{$proxy->password}@{$proxy->ip_address}:{$proxy->port}",
            'ua' => $this->getRandomUA(),
        ] : null;
    }
}
