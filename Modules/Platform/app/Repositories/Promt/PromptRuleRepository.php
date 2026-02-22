<?php

declare(strict_types=1);

namespace Platform\Repositories\Promt;

use Platform\Dto\PromtRule\Step;
use Platform\Models\Promt\PromptRule;

final class PromptRuleRepository
{
    /**
     * Собирает полную инструкцию для системы: Global + (GlobalPrimary | GlobalEstimate) + конкретный шаг
     */
    public function getSystemInstructionsForStep(Step $step): string
    {
        $steps = $this->stepsForInstruction(step: $step);

        $placeholders = implode(', ', array_fill(0, count($steps), '?'));

        return PromptRule::query()
            ->whereIn('step', $steps)
            ->where('is_active', true)
            ->orderByRaw("FIELD(step, {$placeholders})", $steps)
            ->orderBy('priority')
            ->pluck('content')
            ->implode("\n\n---\n\n");
    }

    /**
     * @return array<int, string>
     */
    private function stepsForInstruction(Step $step): array
    {
        $base = [Step::Global->value];

        return match ($step) {
            Step::PrimaryAnalysis => [...$base, Step::GlobalPrimary->value, $step->value],
            Step::EstimatePreview, Step::EstimateFetchItems, Step::EstimateFetchInfo => [...$base, Step::GlobalEstimate->value, $step->value],
            default => [...$base, $step->value],
        };
    }
}
