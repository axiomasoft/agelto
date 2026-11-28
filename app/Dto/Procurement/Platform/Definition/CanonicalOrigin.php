<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Definition;

use App\Dto\Procurement\Process\Primitives\ContentHash;

/**
 * Canonical origin authority: (scheme, IDNA ASCII host, effective port).
 * Origin budget принадлежит именно этой identity, поэтому aliases/capabilities одного origin и
 * любое число proxy делят одну квоту. Userinfo запрещён; путь, query и fragment не входят в origin.
 */
final readonly class CanonicalOrigin
{
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    private function __construct(public string $scheme, public string $host, public int $port) {}

    public static function of(string $scheme, string $host, ?int $port = null): self
    {
        $scheme = strtolower($scheme);
        if (! array_key_exists($scheme, self::DEFAULT_PORTS)) {
            throw new \InvalidArgumentException('Origin scheme must be http or https.');
        }
        $port ??= self::DEFAULT_PORTS[$scheme];
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Origin port is out of range.');
        }

        return new self($scheme, self::host($host), $port);
    }

    public static function fromUrl(string $url): self
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new \InvalidArgumentException('Origin URL must not contain whitespace or control bytes.');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Origin URL requires a scheme and a host.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Origin URL must not contain userinfo.');
        }

        return self::of($parts['scheme'], $parts['host'], $parts['port'] ?? null);
    }

    /** Стабильная строка: default port пишется явно, чтобы `https://h` и `https://h:443` совпадали. */
    public function value(): string
    {
        $host = str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;

        return $this->scheme.'://'.$host.':'.$this->port;
    }

    public function hash(): ContentHash
    {
        return ContentHash::of('origin:v1:'.$this->value());
    }

    /** Единый origin budget всех targets/aliases этого origin. */
    public function budgetScopeKey(): string
    {
        return 'origin:'.$this->hash()->hex;
    }

    /** Probe использует те же origin slots; отдельный scope ограничивает probe concurrency. */
    public function probeScopeKey(): string
    {
        return 'probe:'.$this->hash()->hex;
    }

    public function routeScopeKey(string $routeId): string
    {
        return 'route-origin:'.$routeId.':'.$this->hash()->hex;
    }

    public function egressScopeKey(string $egressId): string
    {
        return 'egress-origin:'.$egressId.':'.$this->hash()->hex;
    }

    public function equals(self $other): bool
    {
        return $this->value() === $other->value();
    }

    private static function host(string $host): string
    {
        $host = rtrim(strtolower(trim($host, '[]')), '.');
        if ($host === '') {
            throw new \InvalidArgumentException('Origin host must not be empty.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return strtolower((string) inet_ntop((string) inet_pton($host)));
        }
        if (preg_match('/[^\x00-\x7f]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if (! is_string($ascii) || $ascii === '') {
                throw new \InvalidArgumentException('Origin host is not a valid IDNA name.');
            }
            $host = strtolower($ascii);
        }
        if (strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $host) !== 1) {
            throw new \InvalidArgumentException('Origin host is not a valid DNS name.');
        }

        return $host;
    }
}
