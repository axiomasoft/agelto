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

    #[GateAbility(permission: EstimatePermission::Create)]
    public function create(User $user): bool
    {
        return $user->hasPermission(EstimatePermission::Create);
    }

    #[GateAbility(permission: EstimatePermission::Update)]
    public function update(User $user, Estimate $estimate): bool
    {
        return $user->hasPermission(EstimatePermission::Update);
    }

    #[GateAbility(permission: EstimatePermission::Delete)]
    public function delete(User $user, Estimate $estimate): bool
    {
        return $user->hasPermission(EstimatePermission::Delete);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission(EstimatePermission::Delete);
    }

    #[GateAbility(permission: EstimatePermission::Restore)]
    public function restore(User $user, Estimate $estimate): bool
    {
        return $user->hasPermission(EstimatePermission::Restore);
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasPermission(EstimatePermission::Restore);
    }

    #[GateAbility(permission: EstimatePermission::ForceDelete)]
    public function forceDelete(User $user, Estimate $estimate): bool
    {
        return $user->hasPermission(EstimatePermission::ForceDelete);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasPermission(EstimatePermission::ForceDelete);
    }

    #[GateAbility(permission: EstimatePermission::Replicate)]
    public function replicate(User $user, Estimate $estimate): bool
    {
        return $user->hasPermission(EstimatePermission::Replicate);
    }

    #[GateAbility(permission: EstimatePermission::Reorder)]
    public function reorder(User $user): bool
    {
        return $user->hasPermission(EstimatePermission::Reorder);
    }
}
