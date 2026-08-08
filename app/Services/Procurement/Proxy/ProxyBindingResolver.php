<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;

/**
 * Единственный носитель привязки прокси к профилям (D38). У стадий карточки и документа нет
 * собственного профиля, поэтому они берут объединение включённых наборов активных профилей
 * реестра закупки; второй pivot на реестр или площадку намеренно не заводится.
 */
final readonly class ProxyBindingResolver
{
    /** @return list<int> */
    public function forCrawler(Crawler $crawler): array
    {
        return $crawler->proxies()
            ->pluck('prc_proxies.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    public function forProcurement(?int $procurementId): array
    {
        if ($procurementId === null) {
            return [];
        }

        return Proxy::query()
            ->join('prc_crawler_proxy', 'prc_crawler_proxy.proxy_id', '=', 'prc_proxies.id')
            ->join('prc_crawlers', 'prc_crawlers.id', '=', 'prc_crawler_proxy.crawler_id')
            ->join('prc_procurements', 'prc_procurements.registry_id', '=', 'prc_crawlers.registry_id')
            ->where('prc_procurements.id', $procurementId)
            ->where('prc_crawlers.is_active', true)
            ->where('prc_crawlers.use_proxy', true)
            ->distinct()
            ->pluck('prc_proxies.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
