<?php

declare(strict_types=1);

namespace Platform\Models\DocumentAnalysis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Models\Crawling\LinkAttachment;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class DocumentAnalysis extends Model implements HasMedia
{
    use InteractsWithMedia;

    public string $mediaPath = 'document-analysis';

    public $timestamps = false;

    protected $table = 'plt_document_analysis';

    protected $fillable = [
        'link_attachment_id',
        'name',
        'extension',
        'status',
        'completed_at',
        'attempts',
        'last_error',
    ];

    public function linkAttachment(): BelongsTo
    {
        return $this->belongsTo(LinkAttachment::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(Details::class);
    }

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}
