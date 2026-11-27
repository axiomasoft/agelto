<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Projection;

use App\Dto\Procurement\Process\Primitives\ProcessValue;

/** Итог одного prune batch: maintenance откладывает весь batch, ineligible события остаются нетронутыми. */
final readonly class PruneResult
{
    public function __construct(public int $pruned, public int $ineligible, public bool $deferredForMaintenance)
    {
        ProcessValue::nonNegative($pruned);
        ProcessValue::nonNegative($ineligible);
    }

    public static function maintenance(): self
    {
        return new self(0, 0, true);
    }
}
