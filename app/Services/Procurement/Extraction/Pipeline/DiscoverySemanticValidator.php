<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Pipeline;

use App\Dto\Procurement\Ai\Output\PageDiscoveryOutput;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Services\Llm\Validation\SemanticValidator;
use Spatie\LaravelData\Data;

/** @implements SemanticValidator<PageDiscoveryOutput> */
final readonly class DiscoverySemanticValidator implements SemanticValidator
{
    /** @param array<int,int> $accepted */
    public function __construct(private PageRange $range, private array $accepted = []) {}

    public function errors(Data $data): array
    {
        if ($data->ambiguous_pages !== []) {
            return ['ambiguous_estimate_boundary'];
        }
        $pages = array_map(static fn ($p): int => $p->page_index, $data->pages);
        if ($pages !== range($this->range->start, $this->range->end - 1)) {
            return ['coverage_gap'];
        }
        $combined = $this->accepted;
        foreach ($data->pages as $page) {
            $combined[$page->page_index] = $page->estimate_ordinal;
        }
        ksort($combined, SORT_NUMERIC);
        $previous = $previousPage = null;
        foreach ($combined as $page => $ordinal) {
            if (($previousPage === $page - 1 && ($ordinal < $previous || $ordinal > $previous + 1))
                || ($page === 0 && $ordinal !== 1)) {
                return ['estimate_identity_gap'];
            }
            $previous = $ordinal;
            $previousPage = $page;
        }

        return [];
    }
}
