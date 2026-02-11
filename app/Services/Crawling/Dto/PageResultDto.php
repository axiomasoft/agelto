<?php

declare(strict_types=1);

namespace App\Services\Crawling\Dto;

use Spatie\LaravelData\Data;

final class PageResultDto extends Data
{
    /**
     * @param  array<int, mixed>  $items
     */
    public function __construct(
        public array $items,
        public PageMetaDto $meta,
    ) {}
}
