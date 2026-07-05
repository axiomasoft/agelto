<?php

declare(strict_types=1);

namespace Eis\Facades;

use Eis\Crawling\EisCrawlingService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void start(\App\Models\Crawler\Crawler $crawler)
 *
 * @mixin EisCrawlingService
 */
final class EisCrawling extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EisCrawlingService::class;
    }
}
