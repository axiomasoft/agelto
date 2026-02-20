<?php

declare(strict_types=1);

namespace Platform\Repositories\Promt;

use Platform\Dto\PromtRule\Step;
use Platform\Models\Promt\PromptRule;

final class PromptRuleRepository
{
    /**
     * Собирает полную инструкцию для системы: Global + Конкретный шаг
     */
    public function getSystemInstructionsForStep(Step $step): string
    {
        return PromptRule::query()
            ->whereIn('step', [Step::Global->value, $step->value])
            ->where('is_active', true)
            ->orderBy('step')
            ->orderBy('priority')
            ->pluck('content')
            ->implode("\n\n---\n\n");
    }
}
