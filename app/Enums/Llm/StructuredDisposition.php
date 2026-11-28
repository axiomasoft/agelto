<?php

declare(strict_types=1);

namespace App\Enums\Llm;

/** Pure validation decisions. Scheduling, financial effects and reasks belong to the owner. */
enum StructuredDisposition: string
{
    case Accepted = 'accepted';
    case InvalidSchema = 'ai_response_invalid';
    case InvalidSemantics = 'semantic_review_required';
    case Incomplete = 'model_response_incomplete';
    case Refusal = 'model_refusal';
    case Unsupported = 'unsupported_provider_outcome';
    case SplitRequired = 'split_required';
}
