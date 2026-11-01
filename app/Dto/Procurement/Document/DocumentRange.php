<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

final readonly class DocumentRange
{
    public function __construct(public int $start, public int $end)
    {
        if ($start < 0 || $end <= $start) {
            throw new \InvalidArgumentException('Expected a nonempty half-open document range.');
        }
    }
}
