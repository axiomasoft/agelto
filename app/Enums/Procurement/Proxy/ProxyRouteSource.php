<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Proxy;

enum ProxyRouteSource: string
{
    case Profile = 'profile';
    case Default = 'default';
    case Direct = 'direct';
}
