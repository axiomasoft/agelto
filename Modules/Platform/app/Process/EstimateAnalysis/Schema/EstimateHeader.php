<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema;

use Spatie\LaravelData\Data;

final class EstimateHeader extends Data
{
    public function __construct(
        public ?EstimateHeaderColumn $pos,
        public ?EstimateHeaderColumn $code,
        public ?EstimateHeaderColumn $name,
        public ?EstimateHeaderColumn $unit,
        public ?EstimateHeaderColumn $quantity,
        public ?EstimateHeaderColumn $price,
    ) {}
}
