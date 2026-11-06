<?php

declare(strict_types=1);

namespace App\Guards\Admin\Policies;

use App\Guards\Admin\Permissions\FixturePermission;
use App\Models\Procurement\Ai\Fixture;
use App\Models\User;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyFor;

#[PolicyFor(FixturePermission::class)]
final class FixturePolicy
{
    #[Decides(FixturePermission::ViewAny)]
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(FixturePermission::ViewAny);
    }

    #[Decides(FixturePermission::View)]
    public function view(User $user, ?Fixture $fixture = null): bool
    {
        return $user->hasPermission(FixturePermission::View);
    }

    #[Decides(FixturePermission::Create)]
    public function create(User $user): bool
    {
        return $user->hasPermission(FixturePermission::Create);
    }

    #[Decides(FixturePermission::Update)]
    public function update(User $user, ?Fixture $fixture = null): bool
    {
        return $user->hasPermission(FixturePermission::Update);
    }

    #[Decides(FixturePermission::Delete)]
    public function delete(User $user, ?Fixture $fixture = null): bool
    {
        return $user->hasPermission(FixturePermission::Delete);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasPermission(FixturePermission::Delete);
    }

    #[Decides(FixturePermission::Restore)]
    public function restore(User $user, ?Fixture $fixture = null): bool
    {
        return $user->hasPermission(FixturePermission::Restore);
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasPermission(FixturePermission::Restore);
    }

    #[Decides(FixturePermission::ForceDelete)]
    public function forceDelete(User $user, ?Fixture $fixture = null): bool
    {
        return $user->hasPermission(FixturePermission::ForceDelete);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasPermission(FixturePermission::ForceDelete);
    }

    #[Decides(FixturePermission::Replicate)]
    public function replicate(User $user, ?Fixture $fixture = null): bool
    {
        return $user->hasPermission(FixturePermission::Replicate);
    }

    #[Decides(FixturePermission::Reorder)]
    public function reorder(User $user): bool
    {
        return $user->hasPermission(FixturePermission::Reorder);
    }
}
