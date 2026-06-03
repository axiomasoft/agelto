<?php

declare(strict_types=1);

namespace App\Dto\Crawling;

use Carbon\Carbon;
use App\Dto\Attachments\AttachmentDto;
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
        public ?string $subject = null,
        public ?string $okved = null,
        public ?string $okvedAdditional = null,
        #[WithCast(DateTimeInterfaceCast::class)]
        public ?Carbon $updatedAt,
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
            'subject' => (string) $data['subject'],
            'okved' => (string) $data['okved'],
            'okvedAdditional' => (string) $data['okved_additional'],
            'updatedAt' => isset($data['updated_at']) ? Carbon::parse($data['updated_at']) : null,
            'publishedAt' => isset($data['published_at']) ? Carbon::parse($data['published_at']) : now(),
            'submissionEndAt' => isset($data['submission_end_at']) ? Carbon::parse($data['submission_end_at']) : null,
            'rawPayload' => $data['raw_payload'] ?? [],
            'attachments' => $data['attachments'] ?? [],
        ]);
    }
}
