<?php

declare(strict_types=1);

namespace Platform\Models\Crawling;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Link extends Model
{
    // Указываем новое короткое имя таблицы
    protected $table = 'plt_crawler_links';

    protected $fillable = [
        'crawler_id',
        'reg_number',
        'detail_url',
        'published_at',
        'eis_updated_at',
        'submission_end_at',
        'status',
        'attempts',
        'last_error',
        'next_retry_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'eis_updated_at' => 'datetime',
        'submission_end_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function crawler(): BelongsTo
    {
        return $this->belongsTo(Crawler::class);
    }
}
