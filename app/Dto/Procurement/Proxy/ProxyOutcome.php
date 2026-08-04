<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Proxy;

use App\Enums\Procurement\Proxy\ProxyFailureReason;

/**
 * Исход запроса, отчитываемый резолверу через `reportOutcome()` (research/02 §7, §9.1).
 * HTTP 5xx от ЦЕЛИ отказом прокси не считается — уходит как `success()` (§5).
 */
final readonly class ProxyOutcome
{
    private function __construct(
        public bool $successful,
        public ?ProxyFailureReason $reason,
        public ?int $latencyMs,
    ) {}

    public static function success(?int $latencyMs = null): self
    {
        return new self(successful: true, reason: null, latencyMs: $latencyMs);
    }

    public static function failure(ProxyFailureReason $reason): self
    {
        return new self(successful: false, reason: $reason, latencyMs: null);
    }
}
