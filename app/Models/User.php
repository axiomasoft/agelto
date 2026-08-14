<?php

namespace App\Models;

use AzGuard\Concerns\HasAzGuard;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use AzGuard\Contracts\AzGuardUser;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
class User extends Authenticatable implements AzGuardUser, FilamentUser
{
    use HasAzGuard, HasFactory, Notifiable;

    /**
     * Доступ к Filament-панели: суперадмин или пользователь с любой ролью.
     * Внутри панели видимость каждого ресурса дальше режет ResourceGate AzGuard
     * по permission-каталогу — здесь только грубый вход на панель.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isSuperAdmin() || $this->getRoleNames()->isNotEmpty();
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
