<?php

declare(strict_types=1);

namespace App\Services\Procurement\Crawler;

use App\Enums\Procurement\Crawler\CrawlerExecutionLane;

/**
 * Атомарная карта execution lane в пару connection + queue (P4.7, D117).
 *
 * Раздельные `match` для connection и queue запрещены: новая ветка могла бы попасть в один из
 * двух носителей, оставив job на очереди с неподходящим retry_after.
 */
final readonly class CrawlQueueRoute
{
    private function __construct(
        public string $connection,
        public string $queue,
    ) {}

    public static function forLane(CrawlerExecutionLane $lane): self
    {
        return match ($lane) {
            CrawlerExecutionLane::Standard => new self(connection: 'redis', queue: 'crawl'),
            CrawlerExecutionLane::Long => new self(connection: 'redis_crawl_long', queue: 'crawl_long'),
        };
    }
}
