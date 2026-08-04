<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Enums\Procurement\Ai\AnalysisRunStatus;
use App\Enums\Procurement\Ai\RunMode;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\User;
use Database\Factories\Procurement\Ai\AnalysisRunFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Один запуск разбора одной версии документа (§3 root/document-contour.md, D93): скрининг →
 * загрузка в кэш провайдера → извлечение — цепочка `Ai\Analysis`/`llm_calls`, объединённая
 * этой строкой. Partial unique `(document_version_id) WHERE finished_at IS NULL` — второй
 * незавершённый прогон той же версии отвергается базой (§3.2).
 *
 * @property int $id
 * @property string $uuid
 * @property int $document_version_id
 * @property RunMode $mode
 * @property AnalysisRunStatus $status
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $cached_tokens
 * @property int|null $total_tokens
 * @property int|null $cost_micros
 * @property int|null $initiated_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
#[UseFactory(AnalysisRunFactory::class)]
final class AnalysisRun extends Model
{
    use HasFactory;

    protected $table = 'prc_ai_analysis_runs';

    protected $fillable = [
        'uuid',
        'document_version_id',
        'mode',
        'status',
        'prompt_tokens',
        'completion_tokens',
        'cached_tokens',
        'total_tokens',
        'cost_micros',
        'initiated_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => RunMode::class,
            'status' => AnalysisRunStatus::class,
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cached_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_micros' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(related: DocumentVersion::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(related: User::class, foreignKey: 'initiated_by');
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(related: Analysis::class);
    }

    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            $run->uuid ??= (string) Str::uuid7();
        });
    }
}
