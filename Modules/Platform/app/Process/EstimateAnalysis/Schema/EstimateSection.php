<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema;

use Spatie\LaravelData\Data;

/** Раздел сметы. type: "section", name, children: (EstimateSubSection | EstimateItem)[]. */
final class EstimateSection extends Data
{
    /**
     * @param  array<int, EstimateSubSection|EstimateItem>  $children
     */
    public function __construct(
        public string $type = 'section',
        public string $name,
        public array $children = [],
    ) {}
}
