<?php

declare(strict_types=1);

namespace App\Models\Crawler;

use App\Enums\Crawling\CrawlingStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
        'content_hash',
        'status',
        'attempts',
        'last_error',
        'last_error_at',
        'next_retry_at',
        'failed_permanently',
        'downloaded_at',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }

    public function documentAnalyses(): MorphMany
    {
        return $this->morphMany(DocumentAnalysis::class, name: 'model');
    }

    protected function casts(): array
    {
        return [
            'status' => CrawlingStatus::class,
            'attempts' => 'integer',
            'failed_permanently' => 'boolean',
            'last_error_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }
}
