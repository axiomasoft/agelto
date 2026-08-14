<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Proxy;

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
            attributes: ['proxy_id' => $proxyId, 'target' => $target],
            values: ['success_ratio' => 1.0, 'consecutive_failures' => 0, 'open_count' => 0],
        );
    }

    /**
     * Locks the two health carriers in the only permitted order: proxy, then target.
     *
     * The caller must retain the surrounding transaction until its outcome is persisted. The
     * proxy lock serializes first creation of a target row, after which the exact target row is
     * locked before its breaker and EWMA values are read.
     *
     * @return array{proxy: Proxy, target: ProxyTarget}
     */
    public function lockProxyAndTarget(int $proxyId, string $target): array
    {
        $proxy = Proxy::query()->lockForUpdate()->findOrFail($proxyId);

        $this->findOrCreateTarget(proxyId: $proxy->id, target: $target);

        $lockedTarget = ProxyTarget::query()
            ->where('proxy_id', $proxy->id)
            ->where('target', $target)
            ->lockForUpdate()
            ->firstOrFail();

        return ['proxy' => $proxy, 'target' => $lockedTarget];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateProxy(Proxy $proxy, array $attributes): void
    {
        $proxy->update($attributes);
    }

    public function reactivate(Proxy $proxy): void
    {
        $proxy->update([
            'is_active' => true,
            'fails_count' => 0,
            'open_count' => 0,
            'open_until' => null,
            'disabled_at' => null,
            'disabled_reason' => null,
        ]);
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
        $configured = config('crawler.proxy.breaker.probe_lock_seconds');

        return is_int($configured) || is_float($configured) || is_string($configured)
            ? (int) $configured
            : 0;
    }
}
