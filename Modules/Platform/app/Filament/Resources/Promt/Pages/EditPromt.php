<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Promt\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Platform\Filament\Resources\Promt\PromtResource;

final class EditPromt extends EditRecord
{
    protected static string $resource = PromtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
