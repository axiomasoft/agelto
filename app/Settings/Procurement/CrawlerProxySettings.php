<?php

declare(strict_types=1);

namespace App\Settings\Procurement;

use Spatie\LaravelSettings\Settings;

final class CrawlerProxySettings extends Settings
{
    public ?int $defaultProxyId;

    public static function group(): string
    {
        return 'procurement_crawler_proxy';
    }

    public static function repository(): string
    {
        return 'procurement';
    }
}
