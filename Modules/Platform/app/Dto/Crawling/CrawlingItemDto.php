<?php

declare(strict_types=1);

namespace Platform\Dto\Crawling;

use Carbon\Carbon;
use Platform\Dto\Attachments\AttachmentDto;

class CrawlingItemDto
{
    public function __construct(
        public string $externalId,
        public string $title,
        public string $url,
        public ?float $price = null,
        public ?Carbon $publishedAt = null,
        public ?Carbon $updatedAt = null,
        public ?Carbon $submissionEndAt = null,
        /** @var AttachmentDto[] */
        public array $attachments = [], // Теперь вложения сразу здесь
    ) {}
}
