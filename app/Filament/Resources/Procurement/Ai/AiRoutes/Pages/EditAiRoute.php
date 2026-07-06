<?php

namespace App\Filament\Resources\Procurement\Ai\AiRoutes\Pages;

use App\Filament\Resources\Procurement\Ai\AiRoutes\AiRouteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAiRoute extends EditRecord
{
    protected static string $resource = AiRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
