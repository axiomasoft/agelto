<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\PromptRule\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Platform\Filament\Resources\PromptRule\PromptRuleResource;

final class EditPromptRule extends EditRecord
{
    protected static string $resource = PromptRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
