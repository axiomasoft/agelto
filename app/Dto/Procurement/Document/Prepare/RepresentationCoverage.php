<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Prepare;

use App\Dto\Procurement\Document\Download\DocumentRange;
use App\Enums\Procurement\Document\CoverageUnit;

final readonly class RepresentationCoverage
{
    /**
     * @param  list<DocumentRange>  $covered
     * @param  list<DocumentRange>  $missing
     * @param  list<PreparePage>  $pages
     */
    public function __construct(public CoverageUnit $unit, public int $sourceUnits, public array $covered, public array $missing, public array $pages = [], public ?string $qualityLabel = null)
    {
        if ($sourceUnits < 1) {
            throw new \InvalidArgumentException('Invalid source coverage.');
        }
        if ($pages !== [] && (count($pages) !== $sourceUnits || ! in_array($qualityLabel, ['complete', 'partial', 'degraded'], true))) {
            throw new \InvalidArgumentException('Full source page manifest required.');
        }
        foreach ($pages as $i => $page) {
            if ($page->page !== $i + 1) {
                throw new \InvalidArgumentException('Manifest must preserve original page anchors.');
            }
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
        $data = ['unit' => $this->unit->value, 'source_units' => $this->sourceUnits,
            'covered' => array_map(fn (DocumentRange $r): array => [$r->start, $r->end], $this->covered),
            'missing' => array_map(fn (DocumentRange $r): array => [$r->start, $r->end], $this->missing),
            'complete' => $this->complete()];
        if ($this->pages !== []) {
            $data['pages'] = array_map(fn (PreparePage $page): array => ['page' => $page->page, 'status' => $page->status->value, 'evidence' => $page->evidence], $this->pages);
            $data['quality_label'] = $this->qualityLabel;
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
