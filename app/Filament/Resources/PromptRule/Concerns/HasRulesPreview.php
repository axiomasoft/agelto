<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptRule\Concerns;

use App\Dto\Prompt\Step;
use App\Repositories\Prompt\PromptRuleRepository;

trait HasRulesPreview
{
    public function getRulesPreviewContentForStep(Step $step): string
    {
        return app(PromptRuleRepository::class)->getSystemInstructionsForStep(step: $step);
    }
}
