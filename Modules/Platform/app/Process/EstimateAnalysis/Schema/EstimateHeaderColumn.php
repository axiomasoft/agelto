<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema\Estimate;

use Spatie\LaravelData\Data;

final class EstimateHeaderColumn extends Data
{
    public function __construct(
        public int $index,
        public string $name,
        public string $full_name,
    ) {}
}
