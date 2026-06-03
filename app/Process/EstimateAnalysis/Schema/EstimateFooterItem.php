<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Schema;

use Spatie\LaravelData\Data;

/** Итоговая строка. label, value, children — вложенные (напр. «в том числе»). */
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
