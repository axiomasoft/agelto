<?php

declare(strict_types=1);

namespace App\Models\Llm;

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use Database\Factories\Llm\LlmCallFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Каждый вызов LLM (партиция `llm_calls` RANGE by `called_at`).
 *
 * `purpose` — СТРОКА объединённого пространства значений (без каста): таблица не несёт
 * `document_type`, поэтому носитель `App\Enums\Llm\LlmPurpose` (интерфейс) резолвится только
 * там, где тип документа известен — `DocumentAnalyzerRegistry::purposeFor()` (P2.5, D101).
 *
 * @property int|null $analysis_run_id
 * @property string|null $request_ref
 * @property string|null $response_ref
 * @property int|null $prompt_tokens
 * @property int|null $cached_tokens
 */
#[UseFactory(LlmCallFactory::class)]
final class LlmCall extends Model
{
    use HasFactory;

    protected $table = 'llm_calls';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'analysis_id',
        'analysis_run_id',
        'purpose',
        'provider',
        'model',
        'prompt_version_set',
        'cache_id',
        'request_ref',
        'response_ref',
        'prompt_tokens',
        'completion_tokens',
        'cached_tokens',
        'total_tokens',
        'cost_micros',
        'success',
        'error',
        'duration_ms',
        'called_at',
    ];

    protected function casts(): array
    {
        return [
            'prompt_version_set' => AsCollection::class,
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cached_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_micros' => 'integer',
            'success' => 'boolean',
            'duration_ms' => 'integer',
            'called_at' => 'datetime',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(related: Analysis::class);
    }

    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(related: AnalysisRun::class);
    }
}
