<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Screening;

/** Semantic policy, sealed with the prompt/schema manifest rather than reread on resume. */
final readonly class ScreeningPolicySnapshot
{
    public function __construct(public float $confidenceLow = 0.4, public float $confidenceHigh = 0.6, public bool $ignoredByRule = false)
    {
        if (! is_finite($confidenceLow) || ! is_finite($confidenceHigh) || $confidenceLow < 0 || $confidenceHigh > 1 || $confidenceLow >= $confidenceHigh) {
            throw new \InvalidArgumentException('Invalid pinned screening confidence policy.');
        }
    }

    /** @return array{confidence_low:float,confidence_high:float,ignored_by_rule:bool} */
    public function fields(): array
    {
        return ['confidence_low' => $this->confidenceLow, 'confidence_high' => $this->confidenceHigh, 'ignored_by_rule' => $this->ignoredByRule];
    }
}
