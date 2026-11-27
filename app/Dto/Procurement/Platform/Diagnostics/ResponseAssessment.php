<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Diagnostics;

use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\FailureScope;

/** Evidence digest identifies the assessed body; raw bodies are never exception/log payloads. */
final readonly class ResponseAssessment
{
    public FailureScope $failureScope;

    public function __construct(
        public Completeness $completeness,
        public AssessmentReason $reason,
        public ContentHash $bodyHash,
        public float $confidence = 1.0,
        public ?ArtifactRef $artifact = null,
        ?FailureScope $failureScope = null,
    ) {
        if (! is_finite($confidence) || $confidence < 0 || $confidence > 1) {
            throw new \InvalidArgumentException('Invalid assessment confidence.');
        }
        $this->failureScope = $failureScope ?? match ($reason) {
            AssessmentReason::Valid, AssessmentReason::Empty => FailureScope::None,
            AssessmentReason::IdentityMismatch => FailureScope::Item,
            AssessmentReason::InvalidResponse, AssessmentReason::UnstableSource => FailureScope::Origin,
            default => FailureScope::Adapter,
        };
    }

    public function usable(): bool
    {
        return in_array($this->completeness, [Completeness::Complete, Completeness::CompleteEmpty], true);
    }
}
