<?php

declare(strict_types=1);

namespace App\Interfaces\Crawling;

use App\Dto\Browser\BrowserOptions;
use HeadlessChromium\Page;
use Throwable;

/**
 * Контракт браузерного клиента для краулинга — DI-шов, позволяющий подменять
 * реальный Chromium-драйвер (BrowserService) тестовым двойником.
 */
interface BrowserClient
{
    public function withRandomProxy(string $driver = 'database'): self;

    public function openPage(string $url, ?BrowserOptions $options = null): Page;

    public function reportSuccess(): void;

    public function reportFail(Throwable $e): void;

    public function close(): void;
}
