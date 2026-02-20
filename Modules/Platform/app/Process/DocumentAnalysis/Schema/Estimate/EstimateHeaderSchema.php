<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema\Estimate;

use Spatie\LaravelData\Data;

final class EstimateHeaderSchema extends Data
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
