<?php

declare(strict_types=1);

namespace Platform\Repositories\Promt;

use Illuminate\Support\Collection;
use Platform\Models\Promt\PromptRule;

final class PromptRuleRepository
{
    /**
     * Собрать финальный текст промпта для конкретной группы.
     *
     * @param  string  $group  Название группы (primary_analysis, detailed_parsing и т.д.)
     */
    public function buildPrompt(string $group): string
    {
        return PromptRule::query()
            ->where('group', $group)
            ->active()
            ->orderBy('priority', 'asc')
            ->pluck('content')
            ->implode("\n\n");
    }

    /**
     * Получить все правила, сгруппированные по ключу 'group'.
     * Полезно для отладки или массовой сборки промпта.
     *
     * @return Collection<string, string>
     */
    public function getAllGrouped(): Collection
    {
        return PromptRule::query()
            ->active()
            ->orderBy('priority')
            ->get()
            ->groupBy('group')
            ->map(fn ($items): string => $items->pluck('content')->implode("\n\n"));
    }
}
