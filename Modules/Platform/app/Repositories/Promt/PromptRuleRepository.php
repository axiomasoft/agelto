<?php

declare(strict_types=1);

namespace Platform\Repositories\Promt;

use Platform\Dto\PromtRule\Group;
use Platform\Models\Promt\PromptRule;

final class PromptRuleRepository
{
    /**
     * Собирает полную инструкцию для системы: Global + Конкретная группа
     */
    public function getSystemInstructionsForGroup(Group $group): string
    {
        return PromptRule::query()
            ->whereIn('group', [Group::Global->value, $group->value])
            ->where('is_active', true)
            ->orderBy('group') // Сначала Global (т.к. у него обычно приоритет или алфавитный порядок)
            ->orderBy('priority')
            ->pluck('content')
            ->implode("\n\n---\n\n"); // Разделитель для ясности
    }
}
