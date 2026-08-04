<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\PromptSuggestionKind;
use App\Enums\Procurement\Ai\PromptSuggestionStatus;
use App\Models\Llm\LlmCall;
use App\Models\User;
use Database\Factories\Procurement\Ai\PromptSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Предложение критика по ТЕКСТУ системной инструкции (D59) — не путать с {@see Correction}:
 * коррекция про КОНКРЕТНЫЙ разбор и подмешивается USER-частью повторного вызова, предложение —
 * про версионируемый файл `resources/ai/prompts/**` и живёт очередью со статусом/аудитом. Патч
 * становится файлом ТОЛЬКО через `ai:prompts:apply` на `status=approved` (D8) — ни одна ветка
 * этой модели не пишет файл сама.
 *
 * @property string $uuid
 * @property PromptSuggestionKind $kind
 * @property PromptSuggestionStatus $status
 * @property int|null $analysis_id
 * @property int|null $fixture_id
 * @property int|null $eval_run_id
 * @property int|null $llm_call_id
 * @property string|null $prompt_key
 * @property string|null $prompt_step
 * @property int|null $base_version
 * @property string|null $proposed_content
 * @property string|null $hint
 * @property string $document_type
 * @property AnalysisStep|null $analysis_step
 * @property string $rationale
 * @property Collection<int, string> $evidence
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $applied_at
 * @property int|null $applied_version
 * @property Carbon|null $reverted_at
 */
#[UseFactory(PromptSuggestionFactory::class)]
final class PromptSuggestion extends Model
{
    use HasFactory;

    protected $table = 'prc_ai_prompt_suggestions';

    protected $fillable = [
        'uuid',
        'kind',
        'status',
        'analysis_id',
        'fixture_id',
        'eval_run_id',
        'llm_call_id',
        'prompt_key',
        'prompt_step',
        'base_version',
        'proposed_content',
        'hint',
        'document_type',
        'analysis_step',
        'rationale',
        'evidence',
        'decided_by',
        'decided_at',
        'decision_note',
        'applied_at',
        'applied_version',
        'reverted_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PromptSuggestionKind::class,
            'status' => PromptSuggestionStatus::class,
            'analysis_step' => AnalysisStep::class,
            'base_version' => 'integer',
            'evidence' => AsCollection::class,
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
            'applied_version' => 'integer',
            'reverted_at' => 'datetime',
        ];
    }

    /**
     * Единственный носитель правила сравнения «то же предложение» (Implementation Rules P7.4):
     * схлопывает пробельные последовательности и обрезает края — используется и при записи
     * нового предложения (дедуп-проверка), и при чтении существующих (`PromptSuggestionReadRepository`).
     */
    public static function normalizeText(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(related: Analysis::class);
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(related: Fixture::class);
    }

    public function evalRun(): BelongsTo
    {
        return $this->belongsTo(related: EvalRun::class);
    }

    public function llmCall(): BelongsTo
    {
        return $this->belongsTo(related: LlmCall::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(related: User::class, foreignKey: 'decided_by');
    }

    protected static function booted(): void
    {
        self::creating(function (self $suggestion): void {
            $suggestion->uuid ??= (string) Str::uuid7();
        });
    }
}
