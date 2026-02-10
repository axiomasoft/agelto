<?php

declare(strict_types=1);

namespace Platform\Dto\Crawling;

use Carbon\Carbon;

final class CrawlingItemDto
{
    public function __construct(
        public string $externalId,
        public string $url,
        public ?string $title = null,
        public ?float $price = null,
        public ?Carbon $publishedAt = null,
        public ?Carbon $submissionEndAt = null,
        public array $raw = []
    ) {}
}
