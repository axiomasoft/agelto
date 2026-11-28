<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Planning;

/** Independent expected source range is authoritative; successful output cannot shrink it. */
final readonly class CoverageMap
{
    /** @param list<RowRange> $covered */
    public function __construct(public RowRange $expected, public array $covered) {}

    public function complete(): bool
    {
        $ranges = $this->covered;
        usort($ranges, fn (RowRange $a, RowRange $b): int => $a->start <=> $b->start);
        $cursor = $this->expected->start;
        foreach ($ranges as $range) {
            if ($range->start > $cursor || $range->start < $this->expected->start || $range->end > $this->expected->end) {
                return false;
            }
            $cursor = max($cursor, $range->end);
        }

        return $cursor === $this->expected->end;
    }
}
