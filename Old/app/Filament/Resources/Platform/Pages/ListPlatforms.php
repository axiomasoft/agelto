<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform\Pages;

use App\Filament\Resources\Platform\PlatformResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

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
