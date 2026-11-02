<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Enums\Procurement\Process\WorkState;
use App\Models\Procurement\Document\DocumentVersion;
use App\Support\Files\Sha256Cast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable canonical recipe/run read model. Execution and spend are derived by AnalysisRunReadRepository.
 *
 * @property string $id
 * @property int $document_version_id
 * @property int $representation_id
 * @property string $recipe_hash
 * @property string $representation_hash
 * @property int $generation
 * @property int $expected_analysis_generation
 * @property string $requested_reason
 * @property string $publication_status
 * @property string|null $screening_disposition
 * @property WorkState $execution_state
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $cached_tokens
 * @property int|null $total_tokens
 * @property int|null $cost_micros
 * @property string|null $cost_currency
 * @property int|null $reserved_micros
 * @property int $calls
 * @property int $unknown_calls
 */
#[Table(name: 'prc_analysis_runs', keyType: 'string', incrementing: false, timestamps: false)]
final class AnalysisRun extends Model
{
    protected function casts(): array
    {
        return ['recipe_hash' => Sha256Cast::class, 'representation_hash' => Sha256Cast::class,
            'generation' => 'integer', 'expected_analysis_generation' => 'integer',
            'execution_state' => WorkState::class, 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
            'prompt_tokens' => 'integer', 'completion_tokens' => 'integer', 'cached_tokens' => 'integer', 'total_tokens' => 'integer',
            'cost_micros' => 'integer', 'reserved_micros' => 'integer', 'calls' => 'integer', 'unknown_calls' => 'integer'];
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    /** @return HasMany<Analysis, $this> */
    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }

    protected static function booted(): void
    {
        self::saving(static function (): never {
            throw new \LogicException('Canonical analysis writes require the durable workflow owner.');
        });
        self::deleting(static function (): never {
            throw new \LogicException('Canonical analysis retention requires its provenance owner.');
        });
    }
}
