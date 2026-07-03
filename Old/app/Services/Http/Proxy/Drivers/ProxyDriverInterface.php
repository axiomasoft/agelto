<?php

declare(strict_types=1);

namespace App\Services\Http\Proxy\Drivers;

interface ProxyDriverInterface
{
    public function getProxy(): ?array;
}
