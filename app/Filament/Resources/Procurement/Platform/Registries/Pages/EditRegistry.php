<?php

namespace App\Filament\Resources\Procurement\Platform\Registries\Pages;

use App\Filament\Resources\Procurement\Platform\Registries\RegistryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRegistry extends EditRecord
{
    protected static string $resource = RegistryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
