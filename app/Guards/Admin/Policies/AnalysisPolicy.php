<?php

declare(strict_types=1);

namespace App\Guards\Admin\Policies;

use App\Guards\Admin\Permissions\AnalysisPermission;
use App\Models\Procurement\Ai\Analysis;
use App\Models\User;
use AzGuard\Attributes\GateAbility;
use AzGuard\Attributes\GuardPolicy;

/**
 * AnalysisResource прежде полагался на enum-RBAC discovery. При отсутствии policy Filament
 * дефолтит непокрытые abilities в allow, что открывало read-side аутентифицированному гостю.
 * Политика сохраняет тот же существующий enum-контракт и не добавляет ни одного права.
 */
#[GuardPolicy(model: Analysis::class)]
final class AnalysisPolicy
{
    #[GateAbility(permission: AnalysisPermission::ViewAny)]
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(AnalysisPermission::ViewAny);
    }

    #[GateAbility(permission: AnalysisPermission::View)]
    public function view(User $user, Analysis $analysis): bool
    {
        return $user->hasPermission(AnalysisPermission::View);
    }

    #[GateAbility(permission: AnalysisPermission::Create)]
    public function create(User $user): bool
    {
        return $user->hasPermission(AnalysisPermission::Create);
    }

    #[GateAbility(permission: AnalysisPermission::Update)]
    public function update(User $user, Analysis $analysis): bool
    {
        return $user->hasPermission(AnalysisPermission::Update);
    }

    #[GateAbility(permission: AnalysisPermission::Delete)]
    public function delete(User $user, Analysis $analysis): bool
    {
        return $user->hasPermission(AnalysisPermission::Delete);
    }

    #[GateAbility(permission: AnalysisPermission::Restore)]
    public function restore(User $user, Analysis $analysis): bool
    {
        return $user->hasPermission(AnalysisPermission::Restore);
    }

    #[GateAbility(permission: AnalysisPermission::ForceDelete)]
    public function forceDelete(User $user, Analysis $analysis): bool
    {
        return $user->hasPermission(AnalysisPermission::ForceDelete);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission(AnalysisPermission::Delete);
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasPermission(AnalysisPermission::Restore);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasPermission(AnalysisPermission::ForceDelete);
    }

    #[GateAbility(permission: AnalysisPermission::Replicate)]
    public function replicate(User $user, Analysis $analysis): bool
    {
        return $user->hasPermission(AnalysisPermission::Replicate);
    }

    #[GateAbility(permission: AnalysisPermission::Reorder)]
    public function reorder(User $user): bool
    {
        return $user->hasPermission(AnalysisPermission::Reorder);
    }
}
