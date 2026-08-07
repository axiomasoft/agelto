<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;

/**
 * In-memory `Crawler` с привязанным (не сохранённым) `Registry` через `setRelation` —
 * позволяет юнит-тестировать `EisCsvUrlBuilder` без обращения к БД.
 */
final class CrawlerWithRegistry
{
    /**
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>|null  $options
     */
    public static function make(string $registryCode, ?array $filter, ?array $options, int $platformId = 1): Crawler
    {
        $registry = new Registry;
        $registry->code = $registryCode;
        $registry->platform_id = $platformId;

        $crawler = new Crawler;
        $crawler->filter = $filter;
        $crawler->options = $options;
        $crawler->setRelation('registry', $registry);

        return $crawler;
    }
}
