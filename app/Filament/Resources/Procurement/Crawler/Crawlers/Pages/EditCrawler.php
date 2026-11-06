<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\Crawlers\Pages;

use App\Filament\Resources\Procurement\Crawler\Crawlers\CrawlerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCrawler extends EditRecord
{
    protected static string $resource = CrawlerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
