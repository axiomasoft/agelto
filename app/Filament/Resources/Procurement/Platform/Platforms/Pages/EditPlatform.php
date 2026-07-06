<?php

namespace App\Filament\Resources\Procurement\Platform\Platforms\Pages;

use App\Filament\Resources\Procurement\Platform\Platforms\PlatformResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlatform extends EditRecord
{
    protected static string $resource = PlatformResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
