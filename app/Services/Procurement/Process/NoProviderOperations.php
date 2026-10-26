<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\WorkId;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryResolver;

/**
 * Resolver baseline P1.11: schema-supported stages (listing/detail/status_refresh/download) не
 * создают provider operations, поэтому нерешённых платных фактов у их work быть не может. Это не
 * разрешение «любой work без provider recovery»: P3.8–P3.14 при активации screening/extraction
 * обязан привязать настоящий resolver через RecoveryReconciler::bindProviderResolver до первого
 * admission такой stage (реальные paid stages здесь не активны).
 */
final readonly class NoProviderOperations implements ProviderRecoveryResolver
{
    public function inspect(WorkId $workId, ?ProviderRecoveryPlan $pinned): ?ProviderRecoveryPlan
    {
        return null;
    }

    public function settleLocked(ProviderRecoveryPlan $plan): void
    {
        throw new \LogicException('No provider operation exists to settle.');
    }

    public function revokeUnclaimedLocked(WorkId $workId): void {}
}
