<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use Database\Factories\Procurement\Crawler\ProxyTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-target здоровье прокси-узла: одна строка на пару (узел, цель), EWMA успешности/задержки
 * вместо оконного агрегата по `prc_fetches` (research/02 §3.2).
 *
 * @property int $id
 * @property int $proxy_id
 * @property string $target
 * @property float $success_ratio
 * @property int|null $latency_ms_avg
 * @property int $consecutive_failures
 * @property Carbon|null $open_until
 * @property int $open_count
 * @property string|null $last_failure_reason
 * @property Carbon|null $last_outcome_at
 * @property-read Proxy $proxy
 */
#[UseFactory(ProxyTargetFactory::class)]
#[Fillable([
    'proxy_id',
    'target',
    'success_ratio',
    'latency_ms_avg',
    'consecutive_failures',
    'open_until',
    'open_count',
    'last_failure_reason',
    'last_outcome_at',
])]
#[Table(name: 'prc_proxy_targets')]
final class ProxyTarget extends Model
{
    use HasFactory;

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
