<?php

declare(strict_types=1);

namespace Platform\Models\DocumentAnalysis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class DocumentAnalysis extends Model implements HasMedia
{
    use InteractsWithMedia;

    public string $mediaPath = 'document-analysis';

    public $timestamps = false;

    protected $table = 'plt_document_analysis';

    protected $fillable = [
        'document_analysable_type',
        'document_analysable_id',
        'parent_id',
        'name',
        'extension',
        'status',
        'completed_at',
        'attempts',
        'last_error',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documentAnalysable(): MorphTo
    {
        return $this->morphTo(name: 'document_analysable');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(DocumentAnalysisResponse::class);
    }

    /**
     * Документ для анализа: приоритет у подготовленного (document-prepared), иначе исходный (document-source).
     */
    public function getDocumentForAnalysis(): ?Media
    {
        return $this->getFirstMedia('document-prepared')
            ?? $this->getFirstMedia('document-source');
    }

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}
