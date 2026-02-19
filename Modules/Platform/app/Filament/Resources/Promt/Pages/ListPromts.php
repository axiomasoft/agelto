<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Promt\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Platform\Filament\Resources\Promt\PromtResource;

final class ListPromts extends ListRecords
{
    protected static string $resource = PromtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
