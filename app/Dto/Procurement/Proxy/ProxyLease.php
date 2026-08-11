<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Proxy;

use App\Enums\Procurement\Proxy\ProxyRouteSource;
use App\Enums\Procurement\Proxy\ProxyScope;

/**
 * Выдача резолвера (research/02 §9.1) — НЕ модель Eloquent: несёт `scope`/`target`, чтобы
 * `reportOutcome()` не терял контекст исхода (сегодняшняя дыра 7 — `ProxyPool::reportFailure()`
 * не знает цели отказа), и дискретные поля `server`/`username`/`password` — прямая форма опции
 * `newContext({proxy})` Playwright, задел под браузерный канал без переделки лизы (§9.3, OQ-4).
 */
final readonly class ProxyLease
{
    private function __construct(
        public ?int $proxyId,
        public ?string $server,
        public ?string $username,
        public ?string $password,
        public ProxyScope $scope,
        public string $target,
        public bool $isDirect,
        public ?ProxyRouteSource $source,
    ) {}

    public static function forProxy(
        int $proxyId,
        string $host,
        int $port,
        ?string $username,
        ?string $password,
        ProxyScope $scope,
        string $target,
        ?ProxyRouteSource $source = null,
    ): self {
        return new self(
            proxyId: $proxyId,
            server: "{$host}:{$port}",
            username: $username,
            password: $password,
            scope: $scope,
            target: $target,
            isDirect: false,
            source: $source,
        );
    }

    /**
     * Typed direct-маршрут (или снятый LLM env-фолбэк) — намеренный режим без узла пула, не
     * отсутствие значения (§9.1).
     */
    public static function direct(ProxyScope $scope, string $target): self
    {
        return new self(
            proxyId: null,
            server: null,
            username: null,
            password: null,
            scope: $scope,
            target: $target,
            isDirect: true,
            source: ProxyRouteSource::Direct,
        );
    }

    /**
     * Вход `Http::withOptions()` — `[]` для direct-лизы.
     *
     * @return array{proxy?: string}
     */
    public function httpOptions(): array
    {
        if ($this->isDirect) {
            return [];
        }

        $credentials = $this->username !== null
            ? rawurlencode($this->username).':'.rawurlencode((string) $this->password).'@'
            : '';

        return ['proxy' => "http://{$credentials}{$this->server}"];
    }
}
