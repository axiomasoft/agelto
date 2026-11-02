<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Services\Llm\Schema\SchemaConstraints;
use Spatie\LaravelData\Data;

final class AnchoredEstimateRow extends Data
{
    public function __construct(
        #[SchemaConstraints(['minimum' => 1])]
        public int $estimate_ordinal,
        #[SchemaConstraints(['minLength' => 1, 'maxLength' => 256])]
        public string $source_anchor,
        #[SchemaConstraints(['minimum' => 0])]
        public int $page_index,
        public EstimateRow $row,
    ) {}
}
