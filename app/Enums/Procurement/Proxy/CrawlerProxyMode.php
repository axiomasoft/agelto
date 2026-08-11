<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Proxy;

enum CrawlerProxyMode: string
{
    case Profile = 'profile';
    case Default = 'default';
    case Direct = 'direct';
}
