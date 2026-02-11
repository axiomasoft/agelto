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

final class LinkDto extends Data
{
    /**
     * @param  DataCollection<int, AttachmentDto>  $attachments
     */
    public function __construct(
        public string $regNumber,
        public string $detailUrl,
        #[WithCast(DateTimeInterfaceCast::class)]
        public Carbon $publishedAt,
        #[WithCast(DateTimeInterfaceCast::class)]
        public ?Carbon $submissionEndAt = null,
        public array $rawPayload = [],
        #[DataCollectionOf(AttachmentDto::class)]
        public DataCollection $attachments = new DataCollection(AttachmentDto::class, []),
    ) {}

    public static function fromArray(array $data): self
    {
        return self::from([
            'regNumber' => (string) $data['reg_number'],
            'detailUrl' => (string) $data['detail_url'],
            'publishedAt' => isset($data['published_at']) ? Carbon::parse($data['published_at']) : now(),
            'submissionEndAt' => isset($data['submission_end_at']) ? Carbon::parse($data['submission_end_at']) : null,
            'rawPayload' => $data['raw_payload'] ?? [],
            'attachments' => $data['attachments'] ?? [],
        ]);
    }
}
