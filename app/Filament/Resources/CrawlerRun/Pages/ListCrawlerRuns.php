<?php

declare(strict_types=1);

namespace App\Filament\Resources\CrawlerRun\Pages;

use App\Filament\Resources\CrawlerRun\CrawlerRunResource;
use Filament\Resources\Pages\ListRecords;

final class ListCrawlerRuns extends ListRecords
{
    protected static string $resource = CrawlerRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
