<?php

declare(strict_types=1);

namespace App\Dto\Crawler;

/**
 * Курсор прогона листинга — сериализуется в/из `crw_runs.cursor` (jsonb). Иерархический:
 * `blockFrom` — общий (fresh/archive); `day`/`priceStack` — только archive (ценовое дробление,
 * `02-crawling.md` §4.2, наполняются в P4); `reachedKnown` — fresh достиг известного/cap-блока.
 */
final readonly class ListingCursor
{
    /**
     * @param  list<string>|null  $priceStack
     */
    public function __construct(
        public int $blockFrom = 1,
        public ?string $day = null,
        public ?array $priceStack = null,
        public bool $reachedKnown = false,
    ) {}

    /**
     * @return array{blockFrom: int, day: string|null, priceStack: list<string>|null, reachedKnown: bool}
     */
    public function toArray(): array
    {
        return [
            'blockFrom' => $this->blockFrom,
            'day' => $this->day,
            'priceStack' => $this->priceStack,
            'reachedKnown' => $this->reachedKnown,
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
        );
    }
}
