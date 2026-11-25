<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Proxyma\ProxymaMaintenanceContext;
use Proxyma\Application\Recovery\Reconciler;
use Proxyma\Domain\Policy\TenantKey;

final readonly class ProxymaReconcileCategory implements ReconcileCategory
{
    public function __construct(private Reconciler $reconciler, private ProxymaMaintenanceContext $maintenance) {}

    public function name(): string
    {
        return 'proxyma_reconcile';
    }

    public function reconcile(): int
    {
        return $this->maintenance->run(
            ability: ProxymaMaintenanceContext::RECONCILE,
            callback: fn (): int => $this->reconciler->run(TenantKey::system())->changed,
        );
    }
}
