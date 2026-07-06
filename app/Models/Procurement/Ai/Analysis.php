<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Models\Procurement\Document\DocumentVersion;
use Database\Factories\Procurement\Ai\AnalysisFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Сессия AI-анализа версии документа (screening/extraction) с lease-локом и кэшем LLM.
 *
 * @use HasFactory<AnalysisFactory>
 */
final class Analysis extends Model
{
    /** @use HasFactory<AnalysisFactory> */
    use HasFactory;

    protected $table = 'prc_ai_analyses';

    protected $fillable = [
        'uuid',
        'document_version_id',
        'step',
        'status',
        'document_type',
        'result',
        'provider',
        'model',
        'locked_by',
        'locked_until',
        'attempts',
        'last_error',
        'last_error_at',
        'next_retry_at',
        'failed_permanently',
        'started_at',
        'finished_at',
        'cache_id',
        'cache_expires_at',
        'rules_hash',
        'progress',
    ];

    protected function casts(): array
    {
        return [
            'step' => AnalysisStep::class,
            'status' => AnalysisStatus::class,
            'result' => AsCollection::class,
            'locked_until' => 'datetime',
            'last_error_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'attempts' => 'integer',
            'failed_permanently' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cache_expires_at' => 'datetime',
            'progress' => AsCollection::class,
        ];
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(related: DocumentVersion::class);
    }

    /**
     * Анализы, для которых имеет смысл повторная попытка: срок ретрая наступил
     * (или не назначен) и анализ ещё не помечен как окончательно неудавшийся.
     *
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
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
        self::creating(function (self $analysis): void {
            $analysis->uuid ??= (string) Str::uuid7();
        });
    }
}
