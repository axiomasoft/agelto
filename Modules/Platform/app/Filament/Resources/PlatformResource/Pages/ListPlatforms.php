<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Resources\PlatformResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Platform\Filament\Resources\PlatformResource;

final class ListPlatforms extends ListRecords
{
    protected static string $resource = PlatformResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
