<?php

declare(strict_types=1);

namespace App\Guards\Admin\Policies;

use App\Guards\Admin\Permissions\AnalysisPermission;
use App\Models\Procurement\Ai\Analysis;
use App\Models\User;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyFor;

/**
 * AnalysisResource прежде полагался на enum-RBAC discovery. При отсутствии policy Filament
 * дефолтит непокрытые abilities в allow, что открывало read-side аутентифицированному гостю.
 * Политика сохраняет тот же существующий enum-контракт и не добавляет ни одного права.
 */
#[PolicyFor(AnalysisPermission::class)]
final class AnalysisPolicy
{
    #[Decides(AnalysisPermission::ViewAny)]
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(AnalysisPermission::ViewAny);
    }

    #[Decides(AnalysisPermission::Create)]
    public function create(User $user): bool
    {
        return $user->hasPermission(AnalysisPermission::Create);
    }

    #[Decides(AnalysisPermission::Update)]
    public function update(User $user, ?Analysis $analysis = null): bool
    {
        return $user->hasPermission(AnalysisPermission::Update);
    }

    #[Decides(AnalysisPermission::Delete)]
    public function delete(User $user, ?Analysis $analysis = null): bool
    {
        return $user->hasPermission(AnalysisPermission::Delete);
    }

    #[Decides(AnalysisPermission::Restore)]
    public function restore(User $user, ?Analysis $analysis = null): bool
    {
        return $user->hasPermission(AnalysisPermission::Restore);
    }

    #[Decides(AnalysisPermission::ForceDelete)]
    public function forceDelete(User $user, ?Analysis $analysis = null): bool
    {
        return $user->hasPermission(AnalysisPermission::ForceDelete);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasPermission(AnalysisPermission::Restore);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasPermission(AnalysisPermission::ForceDelete);
    }

    #[Decides(AnalysisPermission::Replicate)]
    public function replicate(User $user, ?Analysis $analysis = null): bool
    {
        return $user->hasPermission(AnalysisPermission::Replicate);
    }

    #[Decides(AnalysisPermission::Reorder)]
    public function reorder(User $user): bool
    {
        return $user->hasPermission(AnalysisPermission::Reorder);
    }
}
