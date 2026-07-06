<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\FetchFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Забор одного блока данных площадки (партиция `prc_fetches` RANGE by `fetched_at`).
 *
 * @use HasFactory<FetchFactory>
 */
final class Fetch extends Model
{
    /** @use HasFactory<FetchFactory> */
    use HasFactory;

    protected $table = 'prc_fetches';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'crawler_run_id',
        'method',
        'request_params',
        'artifact_ref',
        'http_status',
        'duration_ms',
        'rows_total',
        'rows_new',
        'rows_updated',
        'dedup_signature',
        'proxy_id',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'request_params' => AsCollection::class,
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'rows_total' => 'integer',
            'rows_new' => 'integer',
            'rows_updated' => 'integer',
            'fetched_at' => 'datetime',
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
