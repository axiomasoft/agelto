<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use App\Dto\Procurement\Ai\Screening\ScreeningDecision;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Ai\ScreeningPolicySnapshot;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Ai\ScreeningDisposition;

/** Model confidence never declares an estimate verified; no route/model escalation on resume. */
final class ScreeningPolicy
{
    public function decide(ScreeningResult $result, ScreeningPolicySnapshot $policy): ScreeningDecision
    {
        [$disposition, $reason] = match (true) {
            $policy->ignoredByRule => [ScreeningDisposition::Ignore, ScreeningDecisionReason::IgnoreRule],
            $result->confidence < $policy->confidenceLow => [ScreeningDisposition::Ignore, ScreeningDecisionReason::LowConfidence],
            $result->confidence <= $policy->confidenceHigh => [ScreeningDisposition::Review, ScreeningDecisionReason::BorderlineConfidence],
            $result->document_type === AiDocumentType::Unknown => [ScreeningDisposition::Ignore, ScreeningDecisionReason::UnknownType],
            $result->document_type === AiDocumentType::Estimate => [ScreeningDisposition::Extract, ScreeningDecisionReason::AnalyzerAvailable],
            default => [ScreeningDisposition::Retain, ScreeningDecisionReason::KnownTypeNoAnalyzer],
        };

        return new ScreeningDecision($disposition, $reason, $disposition === ScreeningDisposition::Extract);
    }
}
