<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Proxy;

use App\Enums\Procurement\Proxy\CrawlerProxyMode;

/**
 * Операционное требование вызывающей стороны к резолверу (research/02 §3.3, §6) — сегодня
 * только страна профиля (`prc_crawlers.proxy_country`); отсутствие требования = резолвер страну
 * не сужает. Запрет страны для скоупа (§8) — политика конфига, не требование вызывающей стороны.
 */
final readonly class ProxyRequirements
{
    public function __construct(
        public ?string $country = null,
        /** @var list<int>|null */
        public ?array $proxyIds = null,
        public ?CrawlerProxyMode $crawlerMode = null,
    ) {}
}
