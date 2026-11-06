<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Prompts\Pages;

use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrompts extends ListRecords
{
    protected static string $resource = PromptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
