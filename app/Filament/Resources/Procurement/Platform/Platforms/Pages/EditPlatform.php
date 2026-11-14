<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\Platforms\Pages;

use App\Filament\Concerns\GuardsNetworkSettingsForm;
use App\Filament\Resources\Procurement\Platform\Platforms\PlatformResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlatform extends EditRecord
{
    use GuardsNetworkSettingsForm;

    protected static string $resource = PlatformResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
