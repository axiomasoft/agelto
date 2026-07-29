<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\ReviewStatus;
use App\Models\Procurement\Concerns\HasErrorBlock;
use App\Models\Procurement\Concerns\HasLease;
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
use Illuminate\Support\Str;

/**
 * Сессия AI-анализа версии документа (screening/extraction) с lease-локом и кэшем LLM.
 *
 * Preference-пары для DPO (P9.3) НЕ хранятся отдельной таблицей: chosen выводится из
 * `prc_ai_dataset_examples` (gold), rejected — из `result`/`raw_result` анализа, который
 * этот gold заменил (`parent_analysis_id`) — пара собирается запросом на экспорте.
 *
 * @property int $document_version_id
 * @property AnalysisStep $step
 * @property AnalysisStatus $status
 * @property bool $is_shadow
 * @property string|null $document_type
 * @property int $analysis_run_id
 * @property-read AnalysisRun $analysisRun
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

    protected static function booted(): void
    {
        self::creating(function (self $analysis): void {
            $analysis->uuid ??= (string) Str::uuid7();
        });
    }
}
