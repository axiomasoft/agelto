<?php

declare(strict_types=1);

namespace App\Filament\Resources\CrawlerRun\Pages;

use App\Filament\Resources\CrawlerRun\CrawlerRunResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCrawlerRun extends ViewRecord
{
    protected static string $resource = CrawlerRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
