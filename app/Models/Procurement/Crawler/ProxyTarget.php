<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use Database\Factories\Procurement\Crawler\ProxyTargetFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-target здоровье прокси-узла: одна строка на пару (узел, цель), EWMA успешности/задержки
 * вместо оконного агрегата по `prc_fetches` (research/02 §3.2).
 */
#[UseFactory(ProxyTargetFactory::class)]
final class ProxyTarget extends Model
{
    use HasFactory;

    protected $table = 'prc_proxy_targets';

    protected $fillable = [
        'proxy_id',
        'target',
        'success_ratio',
        'latency_ms_avg',
        'consecutive_failures',
        'open_until',
        'open_count',
        'last_failure_reason',
        'last_outcome_at',
    ];

    protected function casts(): array
    {
        return [
            'success_ratio' => 'float',
            'latency_ms_avg' => 'integer',
            'consecutive_failures' => 'integer',
            'open_until' => 'datetime',
            'open_count' => 'integer',
            'last_outcome_at' => 'datetime',
        ];
    }

    public function proxy(): BelongsTo
    {
        return $this->belongsTo(related: Proxy::class);
    }
}
