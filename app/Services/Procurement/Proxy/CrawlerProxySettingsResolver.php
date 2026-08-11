<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Settings\Procurement\CrawlerProxySettings;

final readonly class CrawlerProxySettingsResolver
{
    public function __construct(
        private CrawlerProxySettings $settings,
    ) {}

    public function defaultProxyId(): ?int
    {
        return $this->settings->refresh()->defaultProxyId;
    }
}
