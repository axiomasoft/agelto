<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Crawler\Pages;

use Filament\Resources\Pages\CreateRecord;
use Platform\Filament\Resources\Crawler\CrawlerResource;

final class CreateCrawler extends CreateRecord
{
    protected static string $resource = CrawlerResource::class;
}
