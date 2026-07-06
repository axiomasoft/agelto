<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Models\Procurement\Crawler\Proxy;

/**
 * Выбор узла прокси-пула и учёт отказов. Креды живут только в `prc_proxies`
 * (модель `Proxy`) — сюда не логируются и не кэшируются.
 */
final readonly class ProxyPool
{
    public function pick(?string $target = null): ?Proxy
    {
        return Proxy::query()
            ->where('is_active', true)
            ->when($target !== null, fn ($query) => $query->whereJsonContains('targets', $target))
            ->orderBy('fails_count')
            ->orderBy('id')
            ->first();
    }

    public function reportFailure(Proxy $proxy): void
    {
        $proxy->increment('fails_count');

        if ($proxy->fails_count >= (int) config('crawler.proxy_max_fails', 5)) {
            $proxy->update(['is_active' => false]);
        }
    }

    public function reportSuccess(Proxy $proxy): void
    {
        $proxy->update(['fails_count' => 0]);
    }

    /**
     * Опции для `Http::withOptions()` — прокси собранный в единую строку.
     *
     * @return array{proxy: string}
     */
    public function proxyOptions(Proxy $proxy): array
    {
        $credentials = $proxy->username !== null
            ? "{$proxy->username}:{$proxy->password}@"
            : '';

        return [
            'proxy' => "http://{$credentials}{$proxy->host}:{$proxy->port}",
        ];
    }
}
