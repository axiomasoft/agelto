<?php

declare(strict_types=1);

namespace App\Dto\Crawling;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class PageResultDto extends Data
{
    /**
     * @param  DataCollection<int, CrawlingItemDto>  $items
     */
    public function __construct(
        #[DataCollectionOf(CrawlingItemDto::class)]
        public DataCollection $items,
        public PageMetaDto $meta,
    ) {}
}
