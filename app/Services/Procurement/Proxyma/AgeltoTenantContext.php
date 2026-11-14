<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma;

use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Policy\TenantContext;

final readonly class AgeltoTenantContext implements TenantContextResolver
{
    public function __construct(private RuntimeCaller $runtime, private UiTenantContext $operators) {}

    public function resolve(): TenantContext
    {
        return $this->runtime->context() ?? $this->operators->resolve();
    }
}
