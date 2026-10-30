<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Enums\Procurement\Proxy\ProxyScope;

/** Node configuration plus a secret name. Password values never enter database or form state. */
final readonly class ProxyNodeConfiguration
{
    /** @param list<ProxyScope> $scopes */
    public function __construct(public string $service, public string $host, public int $port,
        public ?string $username, public ?string $country, public array $scopes, public ?string $secretRef,
        public bool $enabled)
    {
        if ($service === '' || preg_match('/^[a-zA-Z0-9.-]+$/D', $host) !== 1 || $port < 1 || $port > 65535
            || ($secretRef !== null && preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $secretRef) !== 1)
            || ($country !== null && preg_match('/^[A-Z]{2}$/D', $country) !== 1)
            || ($username !== null && preg_match('/[\r\n\x00]/', $username))) {
            throw new \InvalidArgumentException('Invalid proxy node configuration.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $text = static fn (mixed $value): string => is_string($value) ? $value : throw new \InvalidArgumentException('Expected proxy configuration text.');
        $scopes = $data['scopes'] ?? [];
        if (! is_array($scopes) || ! array_is_list($scopes)) {
            throw new \InvalidArgumentException('Expected proxy scopes.');
        }
        $scopes = array_map(static fn (mixed $value): ProxyScope => $value instanceof ProxyScope ? $value : ProxyScope::from($text($value)), $scopes);
        $port = filter_var($data['port'] ?? null, FILTER_VALIDATE_INT);
        if ($port === false) {
            throw new \InvalidArgumentException('Expected proxy port.');
        }

        return new self(service: $text($data['service'] ?? null), host: $text($data['host'] ?? null), port: $port,
            username: isset($data['username']) ? $text($data['username']) : null,
            country: isset($data['country']) ? $text($data['country']) : null, scopes: $scopes,
            secretRef: isset($data['secret_ref']) && $data['secret_ref'] !== '' ? $text($data['secret_ref']) : null,
            enabled: (bool) ($data['enabled'] ?? true));
    }
}
