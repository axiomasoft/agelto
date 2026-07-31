<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Enums\Procurement\ProxyScope;
use Database\Factories\Procurement\Crawler\ProxyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Прокси для обхода площадки (креды хранятся только здесь). Скоуп/здоровье — research/02 §3.1.
 */
#[UseFactory(ProxyFactory::class)]
final class Proxy extends Model
{
    use HasFactory;

    protected $table = 'prc_proxies';

    protected $fillable = [
        'service',
        'host',
        'port',
        'username',
        'password',
        'country',
        'scopes',
        'is_active',
        'fails_count',
        'open_until',
        'open_count',
        'last_failure_reason',
        'last_success_at',
        'last_failure_at',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'scopes' => AsEnumCollection::of(ProxyScope::class),
            'is_active' => 'boolean',
            'fails_count' => 'integer',
            'open_until' => 'datetime',
            'open_count' => 'integer',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(related: ProxyCheck::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(related: ProxyTarget::class);
    }
}
