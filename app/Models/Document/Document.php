<?php

declare(strict_types=1);

namespace App\Models\Document;

use App\Enums\Document\DocumentStatus;
use Database\Factories\Document\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Логический документ закупки (извещение/протокол/контракт/…), независимый от конкретной версии файла.
 *
 * @use HasFactory<DocumentFactory>
 */
final class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected $table = 'doc_documents';

    protected $fillable = [
        'uuid',
        'procurement_id',
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

    protected static function booted(): void
    {
        self::creating(function (self $document): void {
            $document->uuid ??= (string) Str::uuid7();
        });
    }
}
