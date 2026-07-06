<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Enums\Procurement\LlmPurpose;
use Database\Factories\Procurement\Ai\LlmCallFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Каждый вызов LLM (партиция `prc_ai_llm_calls` RANGE by `called_at`).
 *
 * @use HasFactory<LlmCallFactory>
 */
final class LlmCall extends Model
{
    /** @use HasFactory<LlmCallFactory> */
    use HasFactory;

    protected $table = 'prc_ai_llm_calls';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'analysis_id',
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
            'purpose' => LlmPurpose::class,
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
}
