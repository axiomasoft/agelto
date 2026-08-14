<?php

declare(strict_types=1);

namespace App\Guards\Admin\Policies;

use App\Guards\Admin\Permissions\FixturePermission;
use App\Models\Procurement\Ai\Fixture;
use App\Models\User;
use AzGuard\Attributes\GateAbility;
use AzGuard\Attributes\GuardPolicy;

#[GuardPolicy(model: Fixture::class)]
final class FixturePolicy
{
    #[GateAbility(permission: FixturePermission::ViewAny)]
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(FixturePermission::ViewAny);
    }

    #[GateAbility(permission: FixturePermission::View)]
    public function view(User $user, Fixture $fixture): bool
    {
        return $user->hasPermission(FixturePermission::View);
    }

    #[GateAbility(permission: FixturePermission::Create)]
    public function create(User $user): bool
    {
        return $user->hasPermission(FixturePermission::Create);
    }

    #[GateAbility(permission: FixturePermission::Update)]
    public function update(User $user, Fixture $fixture): bool
    {
        return $user->hasPermission(FixturePermission::Update);
    }

    #[GateAbility(permission: FixturePermission::Delete)]
    public function delete(User $user, Fixture $fixture): bool
    {
        return $user->hasPermission(FixturePermission::Delete);
    }
}
