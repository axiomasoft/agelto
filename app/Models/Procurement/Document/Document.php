<?php

declare(strict_types=1);

namespace App\Models\Procurement\Document;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Platform\Platform;
use App\Models\Scopes\PublicPlatformScope;
use Database\Factories\Procurement\Document\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
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
 * @property Carbon|null $removed_at
 * @property Carbon|null $next_retry_at
 * @property string|null $locked_by
 * @property Carbon|null $locked_until
 * @property bool $failed_permanently
 * @property-read int $head_generation Process subject head; не fillable, пишет только Process ledger.
 * @property-read int $acquisition_cycle_seq
 * @property-read Carbon|null $next_revalidation_due_at
 */
#[UseFactory(DocumentFactory::class)]
#[Fillable([
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
    'unprocessable_reason',
    'last_error_at',
    'next_retry_at',
    'failed_permanently',
    'locked_by',
    'locked_until',
])]
#[Table(name: 'prc_documents')]
final class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'unprocessable_reason' => UnprocessableReason::class,
            'removed_at' => 'datetime',
            'last_error_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'next_revalidation_due_at' => 'datetime',
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
     * Единственный носитель правила показа — вырезает
     * документы служебных площадок (`is_public=false`, «Обучалка») из публичной витрины.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopePublicPlatform(Builder $query): Builder
    {
        return PublicPlatformScope::apply($query, 'platform');
    }

    /** @return BelongsTo<Platform, $this> */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(related: Platform::class);
    }

    /** @return HasOne<DocumentVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(related: DocumentVersion::class)->ofMany([
            'downloaded_at' => 'max',
            'id' => 'max',
        ]);
    }

    /** Рабочая копия эталона, если документ создан из fixture. */
    /** @return HasOne<Fixture, $this> */
    public function fixture(): HasOne
    {
        return $this->hasOne(related: Fixture::class);
    }

    protected static function booted(): void
    {
        self::creating(function (self $document): void {
            $document->uuid ??= (string) Str::uuid7();
        });
    }
}
