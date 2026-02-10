<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Crawler\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Platform\Filament\Resources\Crawler\CrawlerResource;

final class EditCrawler extends EditRecord
{
    protected static string $resource = CrawlerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
