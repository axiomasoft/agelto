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

    public const COLLECTION_DOCUMENT_SOURCE = 'document-source';

    public const COLLECTION_DOCUMENT_PREPARED = 'document-prepared';

    public string $mediaPath = 'document-analysis';

    public $timestamps = false;

    protected $table = 'plt_document_analysis';

    protected $fillable = [
        'model_type',
        'model_id',
        'parent_id',
        'name',
        'extension',
        'file_fingerprint',
        'gemini_cache_id',
        'gemini_rules_hash',
        'gemini_cache_expires_at',
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

    /**
     * Владелец анализа (LinkAttachment и др.). Колонки: model_type, model_id.
     */
    public function documentAnalysable(): MorphTo
    {
        return $this->morphTo(name: 'model');
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
        return $this->getFirstMedia(self::COLLECTION_DOCUMENT_PREPARED)
            ?? $this->getFirstMedia(self::COLLECTION_DOCUMENT_SOURCE);
    }

    /**
     * Проверяет, можно ли использовать текущий кэш для данного файла и правил
     */
    public function isCacheValid(string $fingerprint, string $rulesHash): bool
    {
        return $this->gemini_cache_id
            && $this->file_fingerprint === $fingerprint
            && $this->gemini_rules_hash === $rulesHash
            && $this->gemini_cache_expires_at?->isFuture();
    }

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'gemini_cache_expires_at' => 'datetime',
        ];
    }
}
