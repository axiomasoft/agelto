<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\Platforms\Pages;

use App\Filament\Resources\Procurement\Platform\Platforms\PlatformResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlatforms extends ListRecords
{
    protected static string $resource = PlatformResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
