<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Crawler\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Platform\Filament\Resources\Crawler\CrawlerResource;

final class ListCrawlers extends ListRecords
{
    protected static string $resource = CrawlerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
