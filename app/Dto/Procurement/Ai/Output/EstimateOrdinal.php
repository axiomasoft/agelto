<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Output;

final readonly class EstimateOrdinal
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new \InvalidArgumentException('Estimate ordinal starts at one.');
        }
    }
}
