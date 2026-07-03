<?php

declare(strict_types=1);

namespace App\Filament\Resources\Crawler\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\Crawler\CrawlerResource;

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
