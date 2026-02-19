<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class EstimateSectionDto extends Data
{
    /**
     * @param  array<int, EstimateSubSectionDto|EstimateItemDto>  $children
     */
    public function __construct(
        public string $name,
        public array $children = [],
    ) {}
}
