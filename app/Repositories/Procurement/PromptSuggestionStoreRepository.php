<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Enums\Procurement\PromptSuggestionStatus;
use App\Models\Procurement\Ai\PromptSuggestion;

/**
 * Единственная точка персистентности `prc_ai_prompt_suggestions` (D59) — `PromptCritic` решает,
 * какие атрибуты писать при создании, `PromptPatchApplier` — при применении.
 */
final readonly class PromptSuggestionStoreRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PromptSuggestion
    {
        return PromptSuggestion::query()->create($attributes);
    }

    /**
     * `$appliedVersion` — `null` для `correction_hint` (применение не бампает файл промта).
     */
    public function markApplied(PromptSuggestion $suggestion, ?int $appliedVersion): PromptSuggestion
    {
        $suggestion->update([
            'status' => PromptSuggestionStatus::Applied,
            'applied_at' => now(),
            'applied_version' => $appliedVersion,
        ]);

        return $suggestion->refresh();
    }
}
