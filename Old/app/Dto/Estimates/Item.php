<?php

namespace App\Dto\Estimates;

use App\Enums\Estimates\ItemType;
use Spatie\LaravelData\Data;

class Item extends Data
{
    public function __construct(
        public int $row_index,
        public ItemType $type,
        public ?string $section_title,
        public array $fields,
    ) {}
}
