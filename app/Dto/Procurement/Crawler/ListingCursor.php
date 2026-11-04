<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

/**
 * Курсор прогона листинга — сериализуется в/из `prc_crawler_runs.cursor` (jsonb). Иерархический:
 * `blockFrom` — общий (fresh/archive); `day`/`priceStack`/`dateField` — только archive
 * (ценовое дробление, `02-crawling.md` §4.2): `dateField` — агностичная метка, КАКОЕ
 * date-окно дренируется (`publish`|`update`, зеркалит `WindowFilter->dateField`), площадко-
 * специфичное имя query-параметра резолвит `EisCsvUrlBuilder`, не курсор; `reachedKnown`
 * — fresh достиг известного/cap-блока.
 */
final readonly class ListingCursor
{
    /**
     * @param  list<string>|null  $priceStack
     * @param  'publish'|'update'|null  $dateField
     * @param  array{freshCrawlerId: int, filter: array<string, mixed>}|null  $recoveryScope
     */
    public function __construct(
        public int $blockFrom = 1,
        public ?string $day = null,
        public ?array $priceStack = null,
        public bool $reachedKnown = false,
        public ?string $dateField = null,
        public ?string $lastDedupSignature = null,
        public int $blocksInInterval = 0,
        public ?array $recoveryScope = null,
    ) {}

    /**
     * @return array{blockFrom: int, day: string|null, priceStack: list<string>|null, reachedKnown: bool, dateField: string|null, lastDedupSignature: string|null, blocksInInterval: int, recoveryScope: array{freshCrawlerId: int, filter: array<string, mixed>}|null}
     */
    public function toArray(): array
    {
        return [
            'blockFrom' => $this->blockFrom,
            'day' => $this->day,
            'priceStack' => $this->priceStack,
            'reachedKnown' => $this->reachedKnown,
            'dateField' => $this->dateField,
            'lastDedupSignature' => $this->lastDedupSignature,
            'blocksInInterval' => $this->blocksInInterval,
            'recoveryScope' => $this->recoveryScope,
        ];
    }

    /**
     * @param  array<mixed>  $cursor
     */
    public static function fromArray(array $cursor): self
    {
        return new self(
            blockFrom: self::int($cursor['blockFrom'] ?? 1, 'blockFrom'),
            day: self::nullableString($cursor['day'] ?? null, 'day'),
            priceStack: self::nullableStringList($cursor['priceStack'] ?? null, 'priceStack'),
            reachedKnown: (bool) ($cursor['reachedKnown'] ?? false),
            dateField: self::dateField($cursor['dateField'] ?? null),
            lastDedupSignature: self::nullableString($cursor['lastDedupSignature'] ?? null, 'lastDedupSignature'),
            blocksInInterval: self::int($cursor['blocksInInterval'] ?? 0, 'blocksInInterval'),
            recoveryScope: self::recoveryScope($cursor['recoveryScope'] ?? null),
        );
    }

    private static function int(mixed $value, string $key): int
    {
        if (! is_int($value)) {
            throw new \UnexpectedValueException("Listing cursor {$key} must be an integer.");
        }

        return $value;
    }

    private static function nullableString(mixed $value, string $key): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new \UnexpectedValueException("Listing cursor {$key} must be a string or null.");
    }

    /** @return list<string>|null */
    private static function nullableStringList(mixed $value, string $key): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new \UnexpectedValueException("Listing cursor {$key} must be a list of strings or null.");
        }

        $result = [];

        foreach ($value as $part) {
            if (! is_string($part)) {
                throw new \UnexpectedValueException("Listing cursor {$key} must be a list of strings or null.");
            }

            $result[] = $part;
        }

        return $result;
    }

    /** @return 'publish'|'update'|null */
    private static function dateField(mixed $value): ?string
    {
        if ($value === null || $value === 'publish' || $value === 'update') {
            return $value;
        }

        throw new \UnexpectedValueException('Listing cursor dateField must be publish, update, or null.');
    }

    /** @return array{freshCrawlerId: int, filter: array<string, mixed>}|null */
    private static function recoveryScope(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new \UnexpectedValueException('Listing cursor recoveryScope must be an object or null.');
        }

        return RecoveryScope::fromArray($value)->toArray();
    }

    public function withRecoveryScope(RecoveryScope $recoveryScope): self
    {
        return new self(
            blockFrom: $this->blockFrom,
            day: $this->day,
            priceStack: $this->priceStack,
            reachedKnown: $this->reachedKnown,
            dateField: $this->dateField,
            lastDedupSignature: $this->lastDedupSignature,
            blocksInInterval: $this->blocksInInterval,
            recoveryScope: $recoveryScope->toArray(),
        );
    }

    public function withReachedKnown(): self
    {
        return new self(
            blockFrom: $this->blockFrom,
            day: $this->day,
            priceStack: $this->priceStack,
            reachedKnown: true,
            dateField: $this->dateField,
            lastDedupSignature: $this->lastDedupSignature,
            blocksInInterval: $this->blocksInInterval,
            recoveryScope: $this->recoveryScope,
        );
    }

    public function afterFreshBlock(int $blockSize, ?string $dedupSignature): self
    {
        return new self(
            blockFrom: $this->blockFrom + $blockSize,
            day: $this->day,
            priceStack: $this->priceStack,
            reachedKnown: false,
            dateField: $this->dateField,
            lastDedupSignature: $dedupSignature,
            blocksInInterval: $this->blocksInInterval,
            recoveryScope: $this->recoveryScope,
        );
    }
}
