<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/** Dispatch generation одной queue delivery: claim сверяет его с work и admission group под locks. */
final readonly class DeliveryGeneration
{
    public function __construct(public int $value)
    {
        ProcessValue::positive($value);
    }
}
