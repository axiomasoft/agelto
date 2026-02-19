<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class FooterItem extends Data
{
    /**
     * @param  FooterItem[]  $children
     */
    public function __construct(
        public string $label,
        public float $value,
        public array $children = [],
    ) {}
}
