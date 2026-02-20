<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema;

use Spatie\LaravelData\Data;

final class EstimateFooterItem extends Data
{
    /**
     * @param  EstimateFooterItem[]  $children
     */
    public function __construct(
        public string $label,
        public float $value,
        public array $children = [],
    ) {}
}
