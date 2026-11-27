<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Output;

use App\Services\Llm\Schema\SchemaConstraints;
use Spatie\LaravelData\Data;

/** Page coverage is independent of the number of estimate rows on each original page. */
final class PageExtractionOutput extends Data
{
    /**
     * @param  AnchoredEstimateRow[]  $rows
     * @param  FlatTotalNode[]  $totals
     * @param  int[]  $covered_pages
     */
    public function __construct(
        #[SchemaConstraints(['maxItems' => 4096])]
        public array $rows,
        #[SchemaConstraints(['maxItems' => 4096])]
        public array $totals,
        #[SchemaConstraints(['maxItems' => 4096])]
        public array $covered_pages,
    ) {}
}
