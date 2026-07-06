<?php

namespace App\Filament\Resources\Procurement\Crawler\IgnoreRules\Pages;

use App\Filament\Resources\Procurement\Crawler\IgnoreRules\IgnoreRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditIgnoreRule extends EditRecord
{
    protected static string $resource = IgnoreRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
