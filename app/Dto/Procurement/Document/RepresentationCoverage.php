<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

use App\Enums\Procurement\Document\CoverageUnit;

final readonly class RepresentationCoverage
{
    /**
     * @param  list<DocumentRange>  $covered
     * @param  list<DocumentRange>  $missing
     */
    public function __construct(public CoverageUnit $unit, public int $sourceUnits, public array $covered, public array $missing)
    {
        if ($sourceUnits < 1) {
            throw new \InvalidArgumentException('Invalid source coverage.');
        }
        $ranges = [...$covered, ...$missing];
        usort($ranges, fn (DocumentRange $a, DocumentRange $b): int => $a->start <=> $b->start);
        $cursor = 0;
        foreach ($ranges as $range) {
            if ($range->start !== $cursor) {
                throw new \InvalidArgumentException('Coverage overlaps or omits an unaccounted source range.');
            }
            $cursor = $range->end;
        }
        if ($cursor !== $sourceUnits) {
            throw new \InvalidArgumentException('Coverage must account for the entire original source.');
        }
    }

    public function complete(): bool
    {
        return $this->missing === [];
    }

    public function json(): string
    {
        return json_encode(['unit' => $this->unit->value, 'source_units' => $this->sourceUnits,
            'covered' => array_map(fn (DocumentRange $r): array => [$r->start, $r->end], $this->covered),
            'missing' => array_map(fn (DocumentRange $r): array => [$r->start, $r->end], $this->missing),
            'complete' => $this->complete()], JSON_THROW_ON_ERROR);
    }
}
