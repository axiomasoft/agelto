<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages;

use App\Filament\Resources\Procurement\Ai\LlmRoutes\LlmRouteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiRoutes extends ListRecords
{
    protected static string $resource = LlmRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
