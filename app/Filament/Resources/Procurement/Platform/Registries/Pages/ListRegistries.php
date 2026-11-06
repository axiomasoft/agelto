<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\Registries\Pages;

use App\Filament\Resources\Procurement\Platform\Registries\RegistryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegistries extends ListRecords
{
    protected static string $resource = RegistryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
