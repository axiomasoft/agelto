<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema\Estimate;

use Spatie\LaravelData\Data;

final class EstimateSection extends Data
{
    /**
     * @param  array<int, EstimateSubSection|EstimateItem>  $children
     */
    public function __construct(
        public string $name,
        public array $children = [],
    ) {}
}
