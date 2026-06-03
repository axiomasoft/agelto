<?php

declare(strict_types=1);

namespace App\Filament\Resources\Crawler\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\Crawler\CrawlerResource;

final class CreateCrawler extends CreateRecord
{
    protected static string $resource = CrawlerResource::class;
}
