<?php

declare(strict_types=1);

namespace App\Models\Procurement\Document;

use App\Enums\Procurement\DocumentStatus;
use App\Models\Concerns\HasErrorBlock;
use App\Models\Concerns\HasLease;
use App\Models\Procurement\Platform\Platform;
use App\Models\Scopes\PublicPlatformScope;
use Database\Factories\Procurement\Document\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Логический документ закупки (извещение/протокол/контракт/…), независимый от конкретной версии файла.
 *
 * @property string $uuid
 * @property int|null $procurement_id
 * @property int $platform_id
 * @property string|null $external_id
 * @property string $title
 * @property string|null $source_url
 * @property DocumentStatus $status
 * @property int $attempts
 * @property string|null $last_error
 */
#[UseFactory(DocumentFactory::class)]
final class Document extends Model
{
    use HasErrorBlock, HasFactory, HasLease;

    protected $table = 'prc_documents';

    protected $fillable = [
        'uuid',
        'procurement_id',
        'platform_id',
        'external_id',
        'title',
        'kind',
        'source_url',
        'status',
        'first_seen_revision_id',
        'removed_at',
        'attempts',
        'last_error',
        'last_error_at',
        'next_retry_at',
        'failed_permanently',
        'locked_by',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'removed_at' => 'datetime',
            'last_error_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'attempts' => 'integer',
            'failed_permanently' => 'boolean',
            'locked_until' => 'datetime',
        ];
    }

    /**
     * Документы, для которых имеет смысл повторная попытка обработки: срок ретрая наступил
     * (или не назначен) и документ ещё не помечен как окончательно неудавшийся.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopePendingRetry(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->where('failed_permanently', false);
    }

    /**
     * Единственный носитель правила показа (§4 root/document-contour.md, D91) — вырезает
     * документы служебных площадок (`is_public=false`, «Обучалка») из публичной витрины.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopePublicPlatform(Builder $query): Builder
    {
        return PublicPlatformScope::apply($query, 'platform');
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(related: Platform::class);
    }

    protected static function booted(): void
    {
        self::creating(function (self $document): void {
            $document->uuid ??= (string) Str::uuid7();
        });
    }
}
