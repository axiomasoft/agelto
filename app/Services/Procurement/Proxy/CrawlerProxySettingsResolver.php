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
        $this->settings->refresh();

        return $this->settings->defaultProxyId;
    }
}
