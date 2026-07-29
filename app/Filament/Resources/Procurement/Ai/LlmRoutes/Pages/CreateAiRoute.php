<?php

namespace App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages;

use App\Filament\Resources\Procurement\Ai\LlmRoutes\LlmRouteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAiRoute extends CreateRecord
{
    protected static string $resource = LlmRouteResource::class;
}
