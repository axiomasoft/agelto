<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Output;

use App\Services\Llm\Schema\SchemaConstraints;
use Spatie\LaravelData\Data;

/** Canonical v1 response. No model-controlled verified/complete/publication flag. */
final class ExtractionOutput extends Data
{
    /**
     * @param  AnchoredEstimateRow[]  $rows
     * @param  FlatTotalNode[]  $totals
     */
    public function __construct(
        #[SchemaConstraints(['maxItems' => 4096])]
        public array $rows,
        #[SchemaConstraints(['maxItems' => 4096])]
        public array $totals,
    ) {}
}
