<?php

declare(strict_types=1);

namespace Platform\Models\Crawling;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Enums\Crawling\CrawlingStatus;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class LinkAttachment extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    public string $mediaPath = 'crawler-link-attachments';

    protected $table = 'plt_crawler_link_attachments';

    protected $fillable = [
        'link_id',
        'external_id',
        'name',
        'extension',
        'url',
        'status',
        'attempts',
        'last_error',
        'process_status',
        'process_error',
        'process_analysis_report',
        'parse_started_at',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }

    protected function casts(): array
    {
        return [
            'status' => CrawlingStatus::class,
            'process_analysis_report' => 'array',
            'parse_started_at' => 'datetime',
        ];
    }
}
