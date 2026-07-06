<?php

declare(strict_types=1);

namespace App\Dto\Procurement;

/**
 * Курсор прогона листинга — сериализуется в/из `prc_crawler_runs.cursor` (jsonb). Иерархический:
 * `blockFrom` — общий (fresh/archive); `day`/`priceStack`/`dateField` — только archive
 * (ценовое дробление, `02-crawling.md` §4.2): `dateField` — агностичная метка, КАКОЕ
 * date-окно дренируется (`publish`|`update`, зеркалит `WindowFilter->dateField`), площадко-
 * специфичное имя query-параметра резолвит `EisCsvUrlBuilder`, не курсор (D21); `reachedKnown`
 * — fresh достиг известного/cap-блока.
 */
final readonly class ListingCursor
{
    /**
     * @param  list<string>|null  $priceStack
     * @param  'publish'|'update'|null  $dateField
     */
    public function __construct(
        public int $blockFrom = 1,
        public ?string $day = null,
        public ?array $priceStack = null,
        public bool $reachedKnown = false,
        public ?string $dateField = null,
    ) {}

    /**
     * @return array{blockFrom: int, day: string|null, priceStack: list<string>|null, reachedKnown: bool, dateField: string|null}
     */
    public function toArray(): array
    {
        return [
            'blockFrom' => $this->blockFrom,
            'day' => $this->day,
            'priceStack' => $this->priceStack,
            'reachedKnown' => $this->reachedKnown,
            'dateField' => $this->dateField,
        ];
    }

    /**
     * @param  array<string, mixed>  $cursor
     */
    public static function fromArray(array $cursor): self
    {
        return new self(
            blockFrom: (int) ($cursor['blockFrom'] ?? 1),
            day: $cursor['day'] ?? null,
            priceStack: $cursor['priceStack'] ?? null,
            reachedKnown: (bool) ($cursor['reachedKnown'] ?? false),
            dateField: $cursor['dateField'] ?? null,
        );
    }
}
