<?php

declare(strict_types=1);

namespace App\Guards\Admin\Policies;

use App\Guards\Admin\Permissions\EstimatePermission;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\User;
use AzGuard\Attributes\GateAbility;
use AzGuard\Attributes\GuardPolicy;

/**
 * Явный read-only gate витрины смет: без policy Filament разрешал гостю abilities по умолчанию,
 * несмотря на существующий каталог EstimatePermission.
 */
#[GuardPolicy(model: Estimate::class)]
final class EstimatePolicy
{
    #[GateAbility(permission: EstimatePermission::ViewAny)]
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(EstimatePermission::ViewAny);
    }

    #[GateAbility(permission: EstimatePermission::View)]
    public function view(User $user, Estimate $estimate): bool
    {
        return $user->hasPermission(EstimatePermission::View);
    }
}
