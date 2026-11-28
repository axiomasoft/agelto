<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

/** v1 trigger stops NEW billable claims; already claimed operations may exceed the count. */
final readonly class UnknownOperationPolicy
{
    public bool $automaticResendUnknown;

    public ?FinancialAmount $hardExposureCap;

    public function __construct(public int $haltAfterUnknownCount = 1, ?string $hardExposureCap = null)
    {
        if ($haltAfterUnknownCount < 1) {
            throw new \InvalidArgumentException('Unknown hold threshold must be positive.');
        }
        $this->automaticResendUnknown = false;
        $this->hardExposureCap = $hardExposureCap === null ? null : new FinancialAmount($hardExposureCap);
    }
}
