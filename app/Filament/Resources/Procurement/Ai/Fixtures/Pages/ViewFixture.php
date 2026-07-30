<?php

namespace App\Filament\Resources\Procurement\Ai\Fixtures\Pages;

use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFixture extends ViewRecord
{
    protected static string $resource = FixtureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
