<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;

/** Late runtime secret binding; never serialize or log this object. No implicit direct fallback. */
final readonly class HttpRouteBinding
{
    public function __construct(
        public ProcessUuid $id,
        private ?string $proxy = null,
        private ?string $username = null,
        private ?string $password = null,
    ) {
        if ($proxy !== null) {
            $parts = parse_url($proxy);
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'http' || ! isset($parts['host'], $parts['port'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || isset($parts['path']) || preg_match('/[\x00-\x20\x7f]/', $proxy)) {
                throw new \InvalidArgumentException('Only an explicit HTTP CONNECT proxy binding is supported.');
            }
        } elseif ($username !== null || $password !== null) {
            throw new \InvalidArgumentException('Direct bindings cannot carry proxy credentials.');
        }
    }

    public function proxy(): string
    {
        return $this->proxy ?? '';
    }

    public function credentials(): ?string
    {
        return $this->username === null ? null : $this->username.':'.($this->password ?? '');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Runtime outbound bindings cannot be serialized.');
    }
}
