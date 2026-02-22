<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\PromptRule\Concerns;

use Platform\Dto\PromtRule\Step;
use Platform\Repositories\Promt\PromptRuleRepository;

trait HasRulesPreview
{
    public function getRulesPreviewContentForStep(Step $step): string
    {
        return app(PromptRuleRepository::class)->getSystemInstructionsForStep(step: $step);
    }
}
