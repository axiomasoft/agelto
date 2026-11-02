<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

use App\Enums\Llm\ReceiptOutcome;

final readonly class ReceiptAssessment
{
    /** @var numeric-string|null */
    public ?string $cost;

    public function __construct(public ReceiptOutcome $outcome, ?string $cost, public string $policyEvidence)
    {
        $this->cost = $cost === null ? null : FinancialAmount::normalize($cost);
        if ($policyEvidence === ''
            || (in_array($outcome, [ReceiptOutcome::NonbillableCacheMiss, ReceiptOutcome::NonbillableRetry], true) && ($this->cost === null || bccomp($this->cost, '0', 8) !== 0))) {
            throw new \InvalidArgumentException('Missing charge policy evidence.');
        }
    }
}
