<?php

namespace App\Dto\Estimates;

use Spatie\LaravelData\Data;

class Header extends Data
{
    public function __construct(
        public int $columns_count,
        public int $start_row_index,
        public int $end_row_index,
        public int $start_col_index,
        public int $end_col_index,
        public array $schema,
    ) {}
}
