<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use Database\Factories\Procurement\Crawler\ProxyCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Журнал проверок доступности прокси.
 */
#[UseFactory(ProxyCheckFactory::class)]
#[Fillable([
    'proxy_id',
    'checked_at',
    'success',
    'latency_ms',
    'error',
])]
#[Table(name: 'prc_proxy_checks')]
final class ProxyCheck extends Model
{
    /** @use HasFactory<ProxyCheckFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'success' => 'boolean',
            'latency_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<Proxy, $this> */
    public function proxy(): BelongsTo
    {
        return $this->belongsTo(related: Proxy::class);
    }
}
