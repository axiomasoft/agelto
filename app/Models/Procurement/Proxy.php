<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\ProxyFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Прокси для обхода площадки (креды хранятся только здесь).
 *
 * @use HasFactory<ProxyFactory>
 */
final class Proxy extends Model
{
    /** @use HasFactory<ProxyFactory> */
    use HasFactory;

    protected $table = 'prc_proxies';

    protected $fillable = [
        'service',
        'host',
        'port',
        'username',
        'password',
        'targets',
        'is_active',
        'fails_count',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'targets' => AsCollection::class,
            'is_active' => 'boolean',
            'fails_count' => 'integer',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(related: ProxyCheck::class);
    }
}
