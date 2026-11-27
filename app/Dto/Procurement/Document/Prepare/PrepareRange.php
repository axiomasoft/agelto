<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Prepare;

final readonly class PrepareRange
{
    public function __construct(public int $start, public int $end, public int $failures = 0)
    {
        if ($start < 0 || $end <= $start || $failures < 0 || $failures > 1) {
            throw new \InvalidArgumentException('Invalid pending half-open page range.');
        }
    }

    public function length(): int
    {
        return $this->end - $this->start;
    }
}
