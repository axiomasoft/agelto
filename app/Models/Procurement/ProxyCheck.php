<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\ProxyCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Журнал проверок доступности прокси.
 *
 * @use HasFactory<ProxyCheckFactory>
 */
final class ProxyCheck extends Model
{
    /** @use HasFactory<ProxyCheckFactory> */
    use HasFactory;

    protected $table = 'prc_proxy_checks';

    protected $fillable = [
        'proxy_id',
        'checked_at',
        'success',
        'latency_ms',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'success' => 'boolean',
            'latency_ms' => 'integer',
        ];
    }

    public function proxy(): BelongsTo
    {
        return $this->belongsTo(related: Proxy::class);
    }
}
