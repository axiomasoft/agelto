<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Pages;

use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\ParsingRuleSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListParsingRuleSets extends ListRecords
{
    protected static string $resource = ParsingRuleSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
