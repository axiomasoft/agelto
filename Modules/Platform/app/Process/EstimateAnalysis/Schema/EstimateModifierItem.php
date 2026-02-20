<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema\Estimate;

use Spatie\LaravelData\Data;

final class EstimateModifierItem extends Data
{
    public function __construct(
        public string $name,
        public string $description,
        public float $value,
    ) {}
}
