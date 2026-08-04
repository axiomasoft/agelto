<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Enums\Procurement\Ai\PromptSuggestionKind;
use App\Enums\Procurement\Ai\PromptSuggestionStatus;
use App\Models\Procurement\Ai\PromptSuggestion;

/**
 * Чтение очереди `prc_ai_prompt_suggestions` (D59).
 */
final readonly class PromptSuggestionReadRepository
{
    public function find(int $id): ?PromptSuggestion
    {
        return PromptSuggestion::query()->find($id);
    }

    /**
     * Уже существующее `pending`/`rejected`-предложение того же вида на тот же `prompt_key`
     * с нормализованным (`PromptSuggestion::normalizeText()`) текстом, совпадающим с `$normalizedContent`
     * (Implementation Rules P7.4 — очередь не плодит дубли одного и того же предложения).
     */
    public function hasDuplicate(PromptSuggestionKind $kind, ?string $promptKey, string $normalizedContent): bool
    {
        return PromptSuggestion::query()
            ->where('kind', $kind->value)
            ->where('prompt_key', $promptKey)
            ->whereIn('status', [PromptSuggestionStatus::Pending->value, PromptSuggestionStatus::Rejected->value])
            ->get()
            ->contains(fn (PromptSuggestion $suggestion): bool => PromptSuggestion::normalizeText(
                $kind === PromptSuggestionKind::PromptPatch ? $suggestion->proposed_content : $suggestion->hint,
            ) === $normalizedContent);
    }
}
