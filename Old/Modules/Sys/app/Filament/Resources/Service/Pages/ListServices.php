<?php

declare(strict_types=1);

namespace Sys\Filament\Resources\Service\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Sys\Filament\Resources\Service\ServiceResource;

final class ListServices extends ListRecords
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
