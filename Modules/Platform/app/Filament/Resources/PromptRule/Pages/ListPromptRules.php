<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\PromptRule\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Platform\Filament\Resources\PromptRule\PromptRuleResource;

final class ListPromptRules extends ListRecords
{
    protected static string $resource = PromptRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
