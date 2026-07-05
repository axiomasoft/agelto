<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform\Pages;

use App\Filament\Resources\Platform\PlatformResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

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
