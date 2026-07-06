<?php

namespace App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages;

use App\Filament\Resources\Procurement\Ai\DatasetExamples\DatasetExampleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDatasetExample extends EditRecord
{
    protected static string $resource = DatasetExampleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
