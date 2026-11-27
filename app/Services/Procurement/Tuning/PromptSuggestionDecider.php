<?php

declare(strict_types=1);

namespace App\Services\Procurement\Tuning;

use App\Enums\Procurement\Ai\PromptSuggestionStatus;
use App\Exceptions\Procurement\Ai\PromptSuggestionAlreadyDecided;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Repositories\Procurement\Ai\PromptSuggestionStoreRepository;

/**
 * Решение человека по предложению критика: ставит статус и аудит решения — и НИЧЕГО
 * больше. Ни одна ветка не пишет файл промта и не трогает `llm_prompts`: одобренный патч
 * становится файлом только через `ai:prompts:apply`, то есть через коммит, который можно
 * отревьюить в диффе. Экран РЕШАЕТ, файл пишет команда.
 *
 * Переходы — по существующему множеству (`pending` → `approved`/`rejected`); новых статусов
 * очередь не заводит, а `applied`/`reverted` ставит применитель.
 */
final readonly class PromptSuggestionDecider
{
    public function __construct(private PromptSuggestionStoreRepository $suggestions) {}

    /**
     * Заметка при одобрении необязательна: «да» без пояснения читается однозначно.
     */
    public function approve(PromptSuggestion $suggestion, int $decidedBy, ?string $note = null): PromptSuggestion
    {
        return $this->decide($suggestion, PromptSuggestionStatus::Approved, $decidedBy, $note);
    }

    /**
     * Причина отказа ОБЯЗАТЕЛЬНА (тип не даёт её опустить): пустое «нет» через месяц неотличимо
     * от «не смотрели», и очередь перестаёт быть аудитом решений.
     */
    public function reject(PromptSuggestion $suggestion, int $decidedBy, string $note): PromptSuggestion
    {
        return $this->decide($suggestion, PromptSuggestionStatus::Rejected, $decidedBy, $note);
    }

    private function decide(
        PromptSuggestion $suggestion,
        PromptSuggestionStatus $status,
        int $decidedBy,
        ?string $note,
    ): PromptSuggestion {
        if ($suggestion->status !== PromptSuggestionStatus::Pending) {
            throw new PromptSuggestionAlreadyDecided($suggestion->status->value);
        }

        return $this->suggestions->markDecided(
            suggestion: $suggestion,
            status: $status,
            decidedBy: $decidedBy,
            note: $note,
        );
    }
}
