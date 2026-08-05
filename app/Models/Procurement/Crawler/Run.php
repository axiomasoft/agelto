<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Enums\Procurement\Crawler\RunStatus;
use Database\Factories\Procurement\Crawler\RunFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Один прогон краулера: heartbeat, иерархический курсор, ретраи.
 *
 * @property int $id
 * @property int $crawler_id
 * @property RunStatus $status
 * @property Collection<array-key, mixed>|null $cursor
 * @property Collection<array-key, mixed>|null $stats
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $last_heartbeat_at
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 * @property Carbon|null $next_retry_at
 * @property bool $failed_permanently
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[UseFactory(RunFactory::class)]
final class Run extends Model
{
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
