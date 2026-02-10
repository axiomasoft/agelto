<?php

namespace App\Services\Crawling\Dto;

final class PageResultDto
{
    public function __construct(
        /** @var array<int, mixed> */
        public array $items,
        public PageMetaDto $meta,
    ) {}
}