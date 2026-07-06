<?php

declare(strict_types=1);

namespace App\Models\Crawler;

use App\Enums\Crawler\RunStatus;
use Database\Factories\Crawler\RunFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Один прогон краулера: heartbeat, иерархический курсор, ретраи.
 *
 * @use HasFactory<RunFactory>
 */
final class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    protected $table = 'prc_crawler_runs';

    protected $fillable = [
        'crawler_id',
        'status',
        'cursor',
        'stats',
        'started_at',
        'finished_at',
        'last_heartbeat_at',
        'attempts',
        'last_error',
        'last_error_at',
        'next_retry_at',
        'failed_permanently',
    ];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'cursor' => AsCollection::class,
            'stats' => AsCollection::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'attempts' => 'integer',
            'last_error_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'failed_permanently' => 'boolean',
        ];
    }

    public function crawler(): BelongsTo
    {
        return $this->belongsTo(related: Crawler::class);
    }
}
