<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema\Responses;

use Platform\Process\EstimateAnalysis\Schema\EstimateSection;
use Platform\Process\EstimateAnalysis\Schema\EstimateItem;
use Spatie\LaravelData\Data;

final class EstimateFetchResponse extends Data
{
    public function __construct(
        public string $estimate_id,
        /**
         * @param  array<int, EstimateSection|EstimateItem>  $data
         */
        public array $data = [],
    ) {}
}
