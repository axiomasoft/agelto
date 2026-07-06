<?php

namespace App\Filament\Resources\Procurement\Ai\AiRoutes\Pages;

use App\Filament\Resources\Procurement\Ai\AiRoutes\AiRouteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiRoutes extends ListRecords
{
    protected static string $resource = AiRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
