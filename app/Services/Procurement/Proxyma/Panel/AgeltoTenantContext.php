<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Panel;

use App\Services\Procurement\Proxyma\Caller\RuntimeCaller;
use App\Services\Procurement\Proxyma\Catalog\ProxymaMaintenanceContext;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Policy\TenantContext;

final readonly class AgeltoTenantContext implements TenantContextResolver
{
    public function __construct(private RuntimeCaller $runtime, private UiTenantContext $operators, private ProxymaMaintenanceContext $maintenance) {}

    public function resolve(): TenantContext
    {
        return $this->maintenance->context() ?? $this->runtime->context() ?? $this->operators->resolve();
    }
}
