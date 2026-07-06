<?php

namespace App\Filament\Resources\Procurement\Crawler\IgnoreRules\Pages;

use App\Filament\Resources\Procurement\Crawler\IgnoreRules\IgnoreRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIgnoreRules extends ListRecords
{
    protected static string $resource = IgnoreRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
