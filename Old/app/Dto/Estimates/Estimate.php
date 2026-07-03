<?php

namespace App\Dto\Estimates;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class Estimate extends Data
{
    public function __construct(
        public bool $is_candidate, // true, если это похоже на смету
        public ?Header $header,
        /** @var DataCollection<Item>|null */
        public ?DataCollection $items,
    ) {}
}
