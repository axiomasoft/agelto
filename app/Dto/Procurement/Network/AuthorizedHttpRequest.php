<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

/** Single-use send capability returned only AFTER the owning gate commits authorization. */
final class AuthorizedHttpRequest
{
    private bool $consumed = false;

    public function __construct(public readonly OutboundRequestPlan $plan, public readonly HttpRouteBinding $route) {}

    public function consume(): void
    {
        if ($this->consumed) {
            throw new \LogicException('Physical send capability already consumed.');
        }
        $this->consumed = true;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Runtime outbound bindings cannot be serialized.');
    }
}
