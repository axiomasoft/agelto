<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages;

use App\Filament\Resources\Procurement\Ai\LlmRoutes\LlmRouteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAiRoute extends EditRecord
{
    protected static string $resource = LlmRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
