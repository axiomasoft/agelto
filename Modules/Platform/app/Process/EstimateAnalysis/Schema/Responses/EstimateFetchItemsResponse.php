<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema\Responses;

use Platform\Process\DocumentAnalysis\Schema\Estimate\EstimateItem;
use Platform\Process\DocumentAnalysis\Schema\Estimate\EstimateSection;
use Spatie\LaravelData\Data;

final class EstimateFetchItemsResponse extends Data
{
    /**
     * @param  array<int, EstimateSection|EstimateItem>  $data
     */
    public function __construct(
        public bool $success,
        public array $data = [],
        public ?string $message = null,
    ) {}
}
