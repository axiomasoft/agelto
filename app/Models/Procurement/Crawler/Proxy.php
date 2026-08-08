<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Enums\Procurement\Proxy\ProxyScope;
use Database\Factories\Procurement\Crawler\ProxyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Прокси для обхода площадки (креды хранятся только здесь). Скоуп/здоровье — research/02 §3.1.
 *
 * @property int $id
 * @property string $service
 * @property string $host
 * @property int|null $port
 * @property string|null $username
 * @property string|null $password
 * @property string|null $country
 * @property Collection<int, ProxyScope> $scopes
 * @property bool $is_active
 * @property int $fails_count
 * @property Carbon|null $open_until
 * @property int $open_count
 * @property string|null $last_failure_reason
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property Carbon|null $disabled_at
 * @property string|null $disabled_reason
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
        'disabled_at',
        'disabled_reason',
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
            'disabled_at' => 'datetime',
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

    public function crawlers(): BelongsToMany
    {
        return $this->belongsToMany(related: Crawler::class, table: 'prc_crawler_proxy');
    }
}
