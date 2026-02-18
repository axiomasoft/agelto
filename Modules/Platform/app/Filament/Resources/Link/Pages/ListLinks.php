<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Link\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Platform\Filament\Resources\Link\LinkResource;

final class ListLinks extends ListRecords
{
    protected static string $resource = LinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
