<?php

declare(strict_types=1);

namespace App\Models\Crawler;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Прогон обхода краулера: чекпойнт курсора (день/ценовой стек/страница), heartbeat и учёт ошибок.
 * Инвариант — не более одного активного (status=running) прогона на профиль (частичный уникальный индекс).
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

    protected function casts(): array
    {
        return [
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
