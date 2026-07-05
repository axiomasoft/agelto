<?php

declare(strict_types=1);

namespace Sys\Filament\Resources\Proxy\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Sys\Filament\Resources\Proxy\ProxyResource;

final class EditProxy extends EditRecord
{
    protected static string $resource = ProxyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
