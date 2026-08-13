<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Dto\Procurement\Ai\Screening\StoredScreeningResult;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Ai\ReviewStatus;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use App\Models\Concerns\HasErrorBlock;
use App\Models\Concerns\HasLease;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\User;
use Database\Factories\Procurement\Ai\AnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Сессия AI-анализа версии документа (screening/extraction) с lease-локом и кэшем LLM.
 *
 * Preference-пары для DPO (P9.3) НЕ хранятся отдельной таблицей: chosen выводится из
 * `prc_ai_dataset_examples` (gold), rejected — из `result`/`raw_result` анализа, который
 * этот gold заменил (`parent_analysis_id`) — пара собирается запросом на экспорте.
 *
 * @property string $uuid
 * @property int $document_version_id
 * @property AnalysisStep $step
 * @property AnalysisStatus $status
 * @property bool $is_shadow
 * @property string|null $document_type
 * @property Collection<string, mixed>|null $result
 * @property string|null $provider
 * @property string|null $model
 * @property int $attempts
 * @property bool $failed_permanently
 * @property string|null $cache_id
 * @property Carbon|null $cache_expires_at
 * @property string|null $rules_hash
 * @property Collection<string, mixed>|null $progress
 * @property ReviewStatus $review_status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property int $analysis_run_id
 * @property int|null $parent_analysis_id
 * @property Carbon|null $superseded_at
 * @property-read AnalysisRun $analysisRun
 * @property-read DocumentVersion|null $documentVersion
 */
#[UseFactory(AnalysisFactory::class)]
final class Analysis extends Model
{
    use HasErrorBlock, HasFactory, HasLease;

    protected $table = 'prc_ai_analyses';

    protected $fillable = [
        'uuid',
        'document_version_id',
        'analysis_run_id',
        'step',
        'status',
        'document_type',
        'result',
        'provider',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'cached_tokens',
        'total_tokens',
        'cost_micros',
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
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'operator_comment',
        'parent_analysis_id',
        'superseded_at',
        'is_shadow',
    ];

    protected function casts(): array
    {
        return [
            'step' => AnalysisStep::class,
            'status' => AnalysisStatus::class,
            'result' => AsCollection::class,
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cached_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_micros' => 'integer',
            'locked_until' => 'datetime',
            'last_error_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'attempts' => 'integer',
            'failed_permanently' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cache_expires_at' => 'datetime',
            'progress' => AsCollection::class,
            'review_status' => ReviewStatus::class,
            'reviewed_at' => 'datetime',
            'superseded_at' => 'datetime',
            'is_shadow' => 'boolean',
        ];
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(related: DocumentVersion::class);
    }

    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(related: AnalysisRun::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(related: User::class, foreignKey: 'reviewed_by');
    }

    public function parentAnalysis(): BelongsTo
    {
        return $this->belongsTo(related: self::class, foreignKey: 'parent_analysis_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(related: Correction::class);
    }

    /**
     * Сметы, персистированные из результата ИМЕННО этого прогона (`EstimatePersister`, P6.3) —
     * read-путь для Filament (P9.2, «просмотр result/validation_status»).
     */
    public function estimates(): HasMany
    {
        return $this->hasMany(related: Estimate::class);
    }

    public function datasetExamples(): HasMany
    {
        return $this->hasMany(related: DatasetExample::class);
    }

    /**
     * Активные (боевые) анализы: не заменённые перепрогоном и не теневые (P9.1, D24) —
     * то же множество, что покрывает partial unique `(document_version_id, step)`.
     *
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('superseded_at')->where('is_shadow', false);
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

    /**
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    public function scopeActiveScreening(Builder $query): Builder
    {
        return $query->active()->where('step', AnalysisStep::Screening);
    }

    /**
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    public function scopeWithScreeningDisposition(Builder $query, ScreeningDisposition $disposition): Builder
    {
        return $query->activeScreening()
            ->where('result->decision->disposition', $disposition->value);
    }

    /**
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    public function scopeWithScreeningDocumentType(Builder $query, AiDocumentType $documentType): Builder
    {
        return $query->activeScreening()
            ->where('document_type', $documentType->value);
    }

    /**
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    public function scopeWithEstimateCardinality(Builder $query, EstimateCardinality $cardinality): Builder
    {
        return $query->activeScreening()
            ->where('result->screening->estimate_cardinality', $cardinality->value);
    }

    /**
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    public function scopeWithScreeningDecisionReason(Builder $query, ScreeningDecisionReason $reason): Builder
    {
        return $query->activeScreening()
            ->where('result->decision->reason', $reason->value);
    }

    /**
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    public function scopeWithScreeningTag(Builder $query, string $tag): Builder
    {
        return $query->activeScreening()
            ->whereJsonContains('result->screening->tags', $tag);
    }

    /**
     * Полнотекстовый поиск остаётся только по активным v2-screening: выражение в точности
     * соответствует partial GIN-index из P7.5, а точный tag-якорь использует GIN из P7.2.
     *
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    public function scopeWithScreeningSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->activeScreening()
            ->where(function (Builder $query) use ($search): void {
                $query->whereRaw(
                    "to_tsvector('russian', coalesce(result #>> '{screening,summary}', '')) @@ websearch_to_tsquery('russian', ?)",
                    [$search],
                )->orWhereJsonContains('result->screening->tags', $search);
            });
    }

    public function storedScreeningResult(): ?StoredScreeningResult
    {
        if ($this->step !== AnalysisStep::Screening || $this->result === null) {
            return null;
        }

        $value = $this->result->toArray();

        if (($value['schema_version'] ?? null) !== 2) {
            return null;
        }

        try {
            return StoredScreeningResult::fromArray($value);
        } catch (InvalidArgumentException) {
            // Старая/повреждённая запись не должна ломать read-only витрину: raw JSON остаётся
            // доступен для аудита, а typed-поля показывают явный placeholder.
            return null;
        }
    }

    protected static function booted(): void
    {
        self::creating(function (self $analysis): void {
            $analysis->uuid ??= (string) Str::uuid7();
        });
    }
}
