<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Schema\Responses;

use App\Process\EstimateAnalysis\Schema\EstimateSection;
use App\Process\EstimateAnalysis\Schema\EstimateItem;
use Spatie\LaravelData\Data;

/** Дерево позиций сметы. estimate_id, data: (EstimateSection | EstimateItem)[]. */
final class EstimateFetchItems extends Data
{
    public function __construct(
        public string $estimate_id,
        /**
         * @param  array<int, EstimateSection|EstimateItem>  $data
         */
        public array $data = [],
    ) {}
}
