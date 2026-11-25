<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma;

use Proxyma\Contracts\Authorizer;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

final readonly class AgeltoAuthorizer implements Authorizer
{
    public function __construct(private RuntimeCaller $runtime, private UiAuthorizer $operators, private ProxymaMaintenanceContext $maintenance) {}

    public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void
    {
        if ($this->maintenance->context() !== null) {
            $this->maintenance->authorize(actor: $actor, tenant: $tenant, ability: $ability);

            return;
        }

        if ($this->runtime->context() !== null) {
            $this->runtime->authorize($actor, $tenant, $ability, $resourceId);

            return;
        }
        $this->operators->authorize($actor, $tenant, $ability, $resourceId);
    }
}
