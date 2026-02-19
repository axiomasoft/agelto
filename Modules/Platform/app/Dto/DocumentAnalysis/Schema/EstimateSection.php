<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

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
