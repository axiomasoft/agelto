<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\Crawlers\Pages;

use App\Filament\Resources\Procurement\Crawler\Crawlers\CrawlerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCrawlers extends ListRecords
{
    protected static string $resource = CrawlerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
