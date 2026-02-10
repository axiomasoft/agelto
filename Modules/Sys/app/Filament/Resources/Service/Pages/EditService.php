<?php

declare(strict_types=1);

namespace Sys\Filament\Resources\Service\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Sys\Filament\Resources\Service\ServiceResource;

final class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
