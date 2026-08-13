<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use App\Services\Llm\Contracts\OutboundProxy;

/**
 * Адаптер порта `OutboundProxy` (D16) поверх `ProxyResolver` для скоупа `llm`.
 * LLM-трафик получает прокси только от typed resolver: пустой управляемый пул остаётся
 * fail-closed `NoProxyAvailableException`, без неклассифицированного env-фолбэка.
 */
final readonly class PoolOutboundProxy implements OutboundProxy
{
    public function __construct(private ProxyResolver $resolver) {}

    public function optionsFor(string $host): array
    {
        return $this->resolver->resolve(ProxyScope::Llm, $host)->httpOptions();
    }
}
