<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Enums\Procurement\Proxy\ProxyRouteSource;
use Database\Factories\Procurement\Crawler\FetchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Забор одного блока данных площадки (партиция `prc_fetches` RANGE by `fetched_at`).
 */
#[UseFactory(FetchFactory::class)]
#[Fillable([
    'crawler_run_id',
    'stage',
    'procurement_id',
    'wait_ms',
    'method',
    'request_params',
    'artifact_ref',
    'http_status',
    'duration_ms',
    'rows_total',
    'rows_new',
    'rows_updated',
    'rows_parsed',
    'rows_errors',
    'dedup_signature',
    'proxy_id',
    'proxy_source',
    'fetched_at',
])]
#[Table(name: 'prc_fetches', keyType: 'int')]
final class Fetch extends Model
{
    use HasFactory;

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'request_params' => AsCollection::class,
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'procurement_id' => 'integer',
            'wait_ms' => 'integer',
            'rows_total' => 'integer',
            'rows_new' => 'integer',
            'rows_updated' => 'integer',
            'rows_parsed' => 'integer',
            'rows_errors' => 'integer',
            'fetched_at' => 'datetime',
            'proxy_source' => ProxyRouteSource::class,
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(related: Run::class, foreignKey: 'crawler_run_id');
    }

    public function proxy(): BelongsTo
    {
        return $this->belongsTo(related: Proxy::class);
    }
}
