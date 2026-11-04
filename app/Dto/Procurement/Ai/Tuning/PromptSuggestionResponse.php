<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Tuning;

use App\Enums\Procurement\Ai\PromptSuggestionKind;
use Spatie\LaravelData\Data;

/**
 * Ответ LLM-критика промтов (`CorePromptStep::PromptCritic`, `docs/specs/v2/
 * 08-estimate-extraction.md` §6.1) — критик читает diff-отчёт и текущую композицию промта
 * шага и решает ОДИН из двух исходов:
 * - kind: `prompt_patch` — правка версионируемого текста промта, `correction_hint` —
 *   переиспользуемая подсказка для существующего носителя `prc_ai_corrections`.
 * - rationale: чем критик обосновал предложение (обязательно для обоих видов).
 * - evidence: список конкретных адресов расхождений из diff-отчёта, на которые опирается
 *   предложение (например `rows[2].code`, `passport.currency`) — не пересказ diff целиком.
 * - proposed_content: ПОЛНЫЙ новый текст тела промта — заполнено ТОЛЬКО при `kind=prompt_patch`.
 * - hint / document_type: переформулировка и тип документа для `correction_hint` — заполнены
 *   ТОЛЬКО при `kind=correction_hint`.
 */
final class PromptSuggestionResponse extends Data
{
    /**
     * @param  string[]  $evidence
     */
    public function __construct(
        public PromptSuggestionKind $kind,
        public string $rationale,
        public array $evidence,
        public ?string $proposed_content = null,
        public ?string $hint = null,
        public ?string $document_type = null,
    ) {}
}
