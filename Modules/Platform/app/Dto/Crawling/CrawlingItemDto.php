<?php

declare(strict_types=1);

namespace Platform\Dto\Crawling;

use Carbon\Carbon;
use Platform\Dto\Attachments\AttachmentDto;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class CrawlingItemDto extends Data
{
    /**
     * @param  DataCollection<int, AttachmentDto>  $attachments
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public string $url,
        public ?float $price = null,
        #[WithCast(DateTimeInterfaceCast::class)]
        public ?Carbon $publishedAt = null,
        #[WithCast(DateTimeInterfaceCast::class)]
        public ?Carbon $updatedAt = null,
        #[WithCast(DateTimeInterfaceCast::class)]
        public ?Carbon $submissionEndAt = null,
        #[DataCollectionOf(AttachmentDto::class)]
        public DataCollection $attachments = new DataCollection(AttachmentDto::class, []),
    ) {}
}
