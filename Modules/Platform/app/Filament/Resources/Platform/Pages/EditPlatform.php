<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Platform\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Platform\Filament\Resources\Platform\PlatformResource;

final class EditPlatform extends EditRecord
{
    protected static string $resource = PlatformResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
