<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Ai;

/**
 * Статус предложения критика в очереди `prc_ai_prompt_suggestions` (D8/D59): решение о применении
 * принимает ТОЛЬКО человек (`pending` → `approved`/`rejected` → `applied`/`reverted`).
 */
enum PromptSuggestionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Applied = 'applied';
    case Reverted = 'reverted';
}
