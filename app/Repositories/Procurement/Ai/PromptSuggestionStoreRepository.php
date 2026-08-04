<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Enums\Procurement\Ai\PromptSuggestionStatus;
use App\Models\Procurement\Ai\PromptSuggestion;

/**
 * Единственная точка персистентности `prc_ai_prompt_suggestions` (D59) — `PromptCritic` решает,
 * какие атрибуты писать при создании, `PromptSuggestionDecider` — при решении человека,
 * `PromptPatchApplier` — при применении.
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
     * Решение человека: статус + аудит (кто, когда, почему) пишутся ОДНОЙ операцией — разнести их
     * значит допустить запись со статусом без автора. Файла промта не касается: патч применяет
     * `ai:prompts:apply` (D47).
     */
    public function markDecided(
        PromptSuggestion $suggestion,
        PromptSuggestionStatus $status,
        int $decidedBy,
        ?string $note,
    ): PromptSuggestion {
        $suggestion->update([
            'status' => $status,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        return $suggestion->refresh();
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
