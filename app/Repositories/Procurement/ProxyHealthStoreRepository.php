<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\ProxyTarget;

/**
 * Единственная точка персистентности здоровья прокси (`prc_proxies`/`prc_proxy_targets`) для
 * `ProxyResolver` — бизнес-логику (score, breaker) репозиторий не несёт, только чтение/запись
 * по уже принятым ключам (аналог `DocumentStoreRepository`) и атомарный захват half-open пробы:
 * единственность пробы гарантирует условный `UPDATE` с проверкой затронутых строк, не приложение
 * (research/02 §7).
 */
final readonly class ProxyHealthStoreRepository
{
    public function findProxy(int $id): Proxy
    {
        return Proxy::query()->findOrFail($id);
    }

    public function findOrCreateTarget(int $proxyId, string $target): ProxyTarget
    {
        return ProxyTarget::query()->firstOrCreate(
            ['proxy_id' => $proxyId, 'target' => $target],
            ['success_ratio' => 1.0, 'consecutive_failures' => 0, 'open_count' => 0],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateProxy(Proxy $proxy, array $attributes): void
    {
        $proxy->update($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTarget(ProxyTarget $target, array $attributes): void
    {
        $target->update($attributes);
    }

    /**
     * `true` ⟺ ЭТА выдача забрала half-open пробу узла; `false` — пробу забрал другой воркер.
     */
    public function claimGlobalProbe(int $proxyId): bool
    {
        return Proxy::query()
            ->whereKey($proxyId)
            ->where('open_count', '>', 0)
            ->where(fn ($query) => $query->whereNull('open_until')->orWhere('open_until', '<=', now()))
            ->update(['open_until' => now()->addSeconds($this->probeLockSeconds())]) === 1;
    }

    /**
     * `true` ⟺ ЭТА выдача забрала half-open пробу пары (узел, цель).
     */
    public function claimTargetProbe(int $targetId): bool
    {
        return ProxyTarget::query()
            ->whereKey($targetId)
            ->where('open_count', '>', 0)
            ->where(fn ($query) => $query->whereNull('open_until')->orWhere('open_until', '<=', now()))
            ->update(['open_until' => now()->addSeconds($this->probeLockSeconds())]) === 1;
    }

    private function probeLockSeconds(): int
    {
        return (int) config('crawler.proxy.breaker.probe_lock_seconds');
    }
}
