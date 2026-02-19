<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class HeaderSchemaDto extends Data
{
    public function __construct(
        public ?HeaderColumnDto $pos,
        public ?HeaderColumnDto $code,
        public ?HeaderColumnDto $name,
        public ?HeaderColumnDto $unit,
        public ?HeaderColumnDto $quantity,
        public ?HeaderColumnDto $price,
    ) {}
}
