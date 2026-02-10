<?php

declare(strict_types=1);

namespace Platform\Dto\Crawling;

final class PageMetaDto
{
    public function __construct(
        public int $current_page,
        public bool $has_next_page,
        public int $total_items = 0,
        public int $items_per_page = 10,
        public array $next_page_params = [],
    ) {}
}
