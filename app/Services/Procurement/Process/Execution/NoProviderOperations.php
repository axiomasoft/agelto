<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Execution;

use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Dto\Procurement\Process\Provider\ProviderRecoveryPlan;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryResolver;

/**
 * Resolver для work без provider operations: inspect не возвращает recovery plan,
 * settlement запрещён. До admission stage с provider operations composition root должен
 * привязать её resolver через RecoveryReconciler::bindProviderResolver.
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
