<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Dto\Procurement\Proxy\ProxyRequirements;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Models\Procurement\Crawler\Crawler;

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
    public function requirementsForProcurement(?int $procurementId): ProxyRequirements
    {
        if ($procurementId === null) {
            return new ProxyRequirements(crawlerMode: CrawlerProxyMode::Direct);
        }

        $crawlers = Crawler::query()
            ->join('prc_procurements', 'prc_procurements.registry_id', '=', 'prc_crawlers.registry_id')
            ->where('prc_procurements.id', $procurementId)
            ->where('prc_crawlers.is_active', true)
            ->get(['prc_crawlers.*']);
        $profiles = $crawlers->where('proxy_mode', CrawlerProxyMode::Profile);

        if ($profiles->isNotEmpty()) {
            $proxyIds = $profiles->flatMap(fn (Crawler $crawler): array => $this->forCrawler($crawler))->unique()->values()->all();

            return new ProxyRequirements(proxyIds: $proxyIds, crawlerMode: CrawlerProxyMode::Profile);
        }

        if ($crawlers->contains('proxy_mode', CrawlerProxyMode::Default)) {
            return new ProxyRequirements(crawlerMode: CrawlerProxyMode::Default);
        }

        return new ProxyRequirements(crawlerMode: CrawlerProxyMode::Direct);
    }
}
