<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Link\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Platform\Filament\Resources\Link\LinkResource;

final class EditLink extends EditRecord
{
    protected static string $resource = LinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
