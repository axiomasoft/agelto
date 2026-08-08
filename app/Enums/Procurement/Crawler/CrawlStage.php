<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

/** HTTP-стадии, которые обращаются к площадке (P4.1). */
enum CrawlStage: string
{
    case Listing = 'listing';
    case Detail = 'detail';
    case Document = 'document';
}
