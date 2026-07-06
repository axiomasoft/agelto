<?php

namespace App\Models;

use AzGuard\Concerns\HasAzGuard;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use AzGuard\Contracts\AzGuardUser;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[UseFactory(UserFactory::class)]
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
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
