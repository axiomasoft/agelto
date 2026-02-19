<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class HeaderSchema extends Data
{
    public function __construct(
        public ?HeaderColumn $pos,
        public ?HeaderColumn $code,
        public ?HeaderColumn $name,
        public ?HeaderColumn $unit,
        public ?HeaderColumn $quantity,
        public ?HeaderColumn $price,
    ) {}
}
