<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Schema;

use Spatie\LaravelData\Data;

/** Коэффициент (напр. К=1.15). name — код, description — обоснование, value — число. */
final class EstimateModifierItem extends Data
{
    public function __construct(
        public string $name,
        public string $description,
        public float $value,
    ) {}
}
