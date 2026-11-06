<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\Crawlers\Pages;

use App\Filament\Resources\Procurement\Crawler\Crawlers\CrawlerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCrawler extends CreateRecord
{
    protected static string $resource = CrawlerResource::class;
}
