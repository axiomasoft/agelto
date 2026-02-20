<?php

declare(strict_types=1);

namespace Platform\Models\Crawling;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Platform\Enums\Crawling\CrawlingStatus;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
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
        ];
    }
}
