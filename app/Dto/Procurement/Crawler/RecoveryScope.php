<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Dto\Procurement\Crawler\Contracts\RequiresScope;
use App\Models\Procurement\Crawler\Crawler;
use LogicException;
use Spatie\LaravelData\Data;

final readonly class RecoveryScope
{
    /** @param array<string, mixed> $filter */
    public function __construct(
        public int $freshCrawlerId,
        public array $filter,
    ) {
        if ($freshCrawlerId < 1) {
            throw new LogicException('Recovery scope must reference a persisted fresh crawler.');
        }

        if ($filter === []) {
            throw new LogicException('Recovery scope filter must not be empty.');
        }
    }

    public static function fromFreshCrawler(Crawler $crawler): self
    {
        $filter = $crawler->filter;
        $freshCrawlerId = $crawler->getKey();

        if (! $filter instanceof Data || ! $filter instanceof RequiresScope || ! $filter->hasScope()) {
            throw new LogicException('Recovery scope requires a typed fresh crawler filter with a subject scope.');
        }

        if (! is_int($freshCrawlerId)) {
            throw new LogicException('Recovery scope must reference a persisted fresh crawler.');
        }

        return new self(
            freshCrawlerId: $freshCrawlerId,
            filter: self::stringKeyedArray(values: $filter->toArray()),
        );
    }

    /** @param array<mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $freshCrawlerId = $payload['freshCrawlerId'] ?? null;
        $filter = $payload['filter'] ?? null;

        if (! is_int($freshCrawlerId) || ! is_array($filter)) {
            throw new LogicException('Recovery scope cursor is invalid.');
        }

        return new self(
            freshCrawlerId: $freshCrawlerId,
            filter: self::stringKeyedArray(values: $filter),
        );
    }

    /** @param array<mixed> $payload */
    public static function fromPersistedFreshCrawler(Crawler $crawler, array $payload): self
    {
        $scope = self::fromArray(payload: $payload);
        $freshCrawlerId = $crawler->getKey();

        if (! is_int($freshCrawlerId) || $scope->freshCrawlerId !== $freshCrawlerId) {
            throw new LogicException('Recovery scope does not belong to the fresh crawler run.');
        }

        return $scope;
    }

    /** @return array{freshCrawlerId: int, filter: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'freshCrawlerId' => $this->freshCrawlerId,
            'filter' => $this->filter,
        ];
    }

    public function applyTo(Crawler $runtimeCrawler): Crawler
    {
        $crawler = clone $runtimeCrawler;
        $crawler->filter = $this->filter;

        $filter = $crawler->filter;

        if (! $filter instanceof RequiresScope || ! $filter->hasScope()) {
            throw new LogicException('Recovery scope filter lost its typed subject scope.');
        }

        return $crawler;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new LogicException('Recovery scope filter must use string keys.');
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
