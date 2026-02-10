<?php

declare(strict_types=1);

namespace Platform\Dto\Crawling;

final class PageResultDto
{
    public function __construct(
        /** @var CrawlingItemDto[] */
        public array $items,
        public PageMetaDto $meta,
    ) {}
}
