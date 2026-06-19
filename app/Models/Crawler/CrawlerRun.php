<?php

declare(strict_types=1);

namespace App\Models\Crawler;

use App\Enums\Crawler\CrawlerRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Sys\Models\SysLog;

/**
 * Прогон обхода краулера: чекпойнт курсора (день/ценовой стек/страница), heartbeat и учёт ошибок.
 * Инвариант — не более одного активного (status=running) прогона на профиль (частичный уникальный индекс).
 *
 * @property int $id
 * @property int $crawler_id
 * @property CrawlerRunStatus $status
 * @property string|null $cursor_url
 * @property int|null $current_page
 * @property string|null $price_from
 * @property string|null $price_to
 * @property array<int, mixed>|null $price_stack
 * @property \Carbon\CarbonImmutable|null $day_from
 * @property \Carbon\CarbonImmutable|null $day_to
 * @property int|null $cursor_offset
 * @property array<string, mixed>|null $stats
 * @property \Carbon\CarbonImmutable|null $started_at
 * @property \Carbon\CarbonImmutable|null $finished_at
 * @property \Carbon\CarbonImmutable|null $last_heartbeat_at
 * @property int $attempts
 * @property string|null $last_error
 * @property \Carbon\CarbonImmutable|null $last_error_at
 * @property \Carbon\CarbonImmutable|null $next_retry_at
 * @property bool $failed_permanently
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class CrawlerRun extends Model
{
    use HasFactory;

    protected $table = 'plt_crawler_runs';

    protected $fillable = [
        'crawler_id',
        'status',
        'cursor_url',
        'current_page',
        'price_from',
        'price_to',
        'price_stack',
        'day_from',
        'day_to',
        'cursor_offset',
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

    public function crawler(): BelongsTo
    {
        return $this->belongsTo(Crawler::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(Link::class);
    }

    /**
     * Журнал прогона из единой таблицы sys_logs (вехи: старт/возобновление/падение/завершение).
     */
    public function logs(): MorphMany
    {
        return $this->morphMany(SysLog::class, 'entity')->latest();
    }

    protected function casts(): array
    {
        return [
            'status' => CrawlerRunStatus::class,
            'price_stack' => 'array',
            'stats' => 'array',
            'current_page' => 'integer',
            'cursor_offset' => 'integer',
            'attempts' => 'integer',
            'failed_permanently' => 'boolean',
            'price_from' => 'decimal:2',
            'price_to' => 'decimal:2',
            'day_from' => 'date',
            'day_to' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_error_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }
}
