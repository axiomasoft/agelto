<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class HeaderColumnDto extends Data
{
    public function __construct(
        public int $index,
        public string $name,
        public string $full_name,
    ) {}
}
