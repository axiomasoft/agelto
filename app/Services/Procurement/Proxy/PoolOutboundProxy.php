<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use App\Services\Llm\Contracts\OutboundProxy;
use Illuminate\Support\Facades\Log;

/**
 * Адаптер порта `OutboundProxy` (D16) поверх `ProxyResolver` для скоупа `llm` (research/02 §9.2,
 * §10). Пустой пул для `llm` фолбэчит на `config('ai.gemini.proxy')` — прямое требование брифа
 * («env — дефолт, его может и хватить»); гейт запрещённых стран (§8) на env-прокси не
 * распространяется — переменная не несёт метаданных страны, каждое срабатывание фолбэка помечается
 * в логе явно. Пустой пул И пустой env — `NoProxyAvailableException` (тихого прямого запроса не
 * существует, §10); домен `Llm` эту эксепшн-специфику не видит — она рождается и ловится целиком
 * здесь, в прокси-слое.
 */
final readonly class PoolOutboundProxy implements OutboundProxy
{
    public function __construct(private ProxyResolver $resolver) {}

    public function optionsFor(string $host): array
    {
        try {
            return $this->resolver->resolve(ProxyScope::Llm, $host)->httpOptions();
        } catch (NoProxyAvailableException $exception) {
            $envProxy = config('ai.gemini.proxy');

            if (! is_string($envProxy) || $envProxy === '') {
                throw $exception;
            }

            Log::warning('Резолвер прокси: пул для LLM пуст, использован env-фолбэк без гейта стран', [
                'host' => $host,
            ]);

            return ['proxy' => $envProxy];
        }
    }
}
