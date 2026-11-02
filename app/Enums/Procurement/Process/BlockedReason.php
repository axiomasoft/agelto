<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

enum BlockedReason: string
{
    case Paused = 'paused';
    case HandlerHold = 'handler_hold';
    case HandlerNotActivated = 'handler_not_activated';
    case ProviderResultUnknown = 'provider_result_unknown';
    case HandlerFaultLimit = 'handler_fault_limit';
    case DocumentQuality = 'document_quality';
    case ConverterPolicyIncompatible = 'converter_policy_incompatible';
    case PreparationRecipeMismatch = 'preparation_recipe_mismatch';
    case PolicyReview = 'policy_review';
    case InvalidRecipeConfiguration = 'invalid_recipe_configuration';
    case AiResponseInvalid = 'ai_response_invalid';
    case ModelRefusal = 'model_refusal';
    case ModelResponseIncomplete = 'model_response_incomplete';
    case UnsupportedProviderOutcome = 'unsupported_provider_outcome';
    case ExtractionIncomplete = 'extraction_incomplete';
    case SemanticReviewRequired = 'semantic_review_required';
    case ChunkConflict = 'chunk_conflict';
}
