<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Proxy;

/**
 * Typed projection of the resolver's read-only proxy/target query.
 *
 * SQL driver scalars are normalized at the repository boundary so scoring and lease allocation
 * never rely on implicit casts from an untyped database row.
 */
final readonly class ProxyCandidate
{
    public function __construct(
        public int $proxyId,
        public string $host,
        public int $port,
        public ?string $username,
        public ?string $password,
        public int $failsCount,
        public int $proxyOpenCount,
        public ?int $targetId,
        public ?float $successRatio,
        public ?int $latencyMsAvg,
        public ?int $targetOpenCount,
        public float $score = 0.0,
    ) {}

    public function withScore(float $score): self
    {
        return new self(
            proxyId: $this->proxyId,
            host: $this->host,
            port: $this->port,
            username: $this->username,
            password: $this->password,
            failsCount: $this->failsCount,
            proxyOpenCount: $this->proxyOpenCount,
            targetId: $this->targetId,
            successRatio: $this->successRatio,
            latencyMsAvg: $this->latencyMsAvg,
            targetOpenCount: $this->targetOpenCount,
            score: $score,
        );
    }
}
