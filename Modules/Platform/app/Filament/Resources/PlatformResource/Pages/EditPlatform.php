<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\PlatformResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Platform\Filament\Resources\PlatformResource;

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
