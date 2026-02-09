<?php

declare(strict_types=1);

namespace Platform\Dto\Crawling;

use Carbon\Carbon;

final class LinkDto
{
    public function __construct(
        public readonly string $regNumber,
        public readonly string $detailUrl,
        public readonly Carbon $publishedAt,
        public readonly ?Carbon $submissionEndAt = null,
        public readonly array $rawPayload = [], // На случай, если нужно сохранить исходный ответ API
    ) {}

    /**
     * Статический метод для удобного создания из массива (например, из API ответа)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            regNumber: (string) $data['reg_number'],
            detailUrl: (string) $data['detail_url'],
            publishedAt: Carbon::parse($data['published_at']),
            submissionEndAt: isset($data['submission_end_at']) ? Carbon::parse($data['submission_end_at']) : null,
            rawPayload: $data['raw_payload'] ?? [],
        );
    }
}
