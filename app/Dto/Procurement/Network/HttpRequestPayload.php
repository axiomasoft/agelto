<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

/** Runtime-only payload; secrets are never persisted in request DTO/hash/evidence as plaintext. */
final readonly class HttpRequestPayload
{
    /** @var array<string, string> */
    public array $headers;

    /** @param array<string, string> $headers */
    public function __construct(array $headers = [], public ?string $body = null)
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $name = strtolower($name);
            if (preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) !== 1 || preg_match('/[\r\n\x00]/', $value)
                || in_array($name, ['host', 'connection', 'content-length', 'transfer-encoding', 'proxy-authorization', 'accept-encoding'], true)) {
                throw new \InvalidArgumentException('Invalid or transport-owned request header.');
            }
            $normalized[$name] = $value;
        }
        $this->headers = $normalized;
    }

    public function acrossOrigin(): self
    {
        return new self(array_intersect_key($this->headers, array_flip(['accept', 'user-agent'])));
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Runtime outbound bindings cannot be serialized.');
    }
}
