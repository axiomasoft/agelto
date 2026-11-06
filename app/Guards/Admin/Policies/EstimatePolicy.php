<?php

declare(strict_types=1);

namespace App\Guards\Admin\Policies;

use App\Guards\Admin\Permissions\EstimatePermission;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\User;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyFor;

/**
 * Явный read-only gate витрины смет: без policy Filament разрешал гостю abilities по умолчанию,
 * несмотря на существующий каталог EstimatePermission.
 */
#[PolicyFor(EstimatePermission::class)]
final class EstimatePolicy
{
    #[Decides(EstimatePermission::ViewAny)]
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(EstimatePermission::ViewAny);
    }

    #[Decides(EstimatePermission::View)]
    public function view(User $user, ?Estimate $estimate = null): bool
    {
        return $user->hasPermission(EstimatePermission::View);
    }

    #[Decides(EstimatePermission::Create)]
    public function create(User $user): bool
    {
        return $user->hasPermission(EstimatePermission::Create);
    }

    #[Decides(EstimatePermission::Update)]
    public function update(User $user, ?Estimate $estimate = null): bool
    {
        return $user->hasPermission(EstimatePermission::Update);
    }

    #[Decides(EstimatePermission::Delete)]
    public function delete(User $user, ?Estimate $estimate = null): bool
    {
        return $user->hasPermission(EstimatePermission::Delete);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission(EstimatePermission::Delete);
    }

    #[Decides(EstimatePermission::Restore)]
    public function restore(User $user, ?Estimate $estimate = null): bool
    {
        return $user->hasPermission(EstimatePermission::Restore);
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasPermission(EstimatePermission::Restore);
    }

    #[Decides(EstimatePermission::ForceDelete)]
    public function forceDelete(User $user, ?Estimate $estimate = null): bool
    {
        return $user->hasPermission(EstimatePermission::ForceDelete);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasPermission(EstimatePermission::ForceDelete);
    }

    #[Decides(EstimatePermission::Replicate)]
    public function replicate(User $user, ?Estimate $estimate = null): bool
    {
        return $user->hasPermission(EstimatePermission::Replicate);
    }

    #[Decides(EstimatePermission::Reorder)]
    public function reorder(User $user): bool
    {
        return $user->hasPermission(EstimatePermission::Reorder);
    }
}
