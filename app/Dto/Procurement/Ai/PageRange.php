<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

/** Original zero-based pages, half-open. Never byte offsets of a PDF/UTF-8 file. */
final readonly class PageRange
{
    public function __construct(public int $start, public int $end)
    {
        new RowRange($start, $end);
    }
}
