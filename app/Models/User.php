<?php

declare(strict_types=1);

namespace App\Models;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Changes\ChangeResult;
use AzGuard\Concerns\HasAzGuard;
use AzGuard\Contracts\AzGuardSubject;
use AzGuard\Exceptions\RecursionDetectedException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use UnitEnum;

#[UseFactory(UserFactory::class)]
#[Fillable([
    'name',
    'email',
    'password',
])]
#[Hidden([
    'password',
    'remember_token',
])]
class User extends Authenticatable implements AzGuardSubject, FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasAzGuard, HasFactory, Notifiable;

    public function azguardDefaultPanel(): ?string
    {
        return 'admin';
    }

    /**
     * Доступ к Filament-панели: суперадмин или пользователь с любой ролью.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isSuperAdmin() || $this->roleNames()->isNotEmpty();
    }

    public function hasPermission(string|UnitEnum $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null, ?string $panelId = null): bool
    {
        $resolvedGuard = $guard ?? $panelId;
        $access = $this->azguardAccess($resolvedGuard, [$permission]);

        try {
            return $access->hasPermission($permission, $on, $resolvedGuard);
        } catch (RecursionDetectedException) {
            [, $key] = app(Authorizer::class)->resolve($this, $permission, $access->panel()->id());

            return $access->isSuperAdmin($on) || $access->permissionSet($on)->covers($key);
        }
    }

    /**
     * @param  string|UnitEnum|list<string|UnitEnum>  ...$roles
     */
    public function assignRole(string|UnitEnum|array ...$roles): ChangeResult
    {
        /** @var list<string|UnitEnum> $flattened */
        $flattened = [];
        foreach ($roles as $role) {
            if (is_array($role)) {
                array_push($flattened, ...$role);
            } else {
                $flattened[] = $role;
            }
        }

        return $this->grantRole($flattened);
    }

    public function removeRole(string|UnitEnum $role): ChangeResult
    {
        return $this->revokeRole($role);
    }

    /**
     * @return Collection<int, string>
     */
    public function getRoleNames(Model|AssignmentScopeRef|null $on = null, ?string $guard = null): Collection
    {
        return $this->roleNames($on, $guard);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
