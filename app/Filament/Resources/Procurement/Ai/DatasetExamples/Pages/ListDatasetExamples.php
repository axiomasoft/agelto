<?php

namespace App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages;

use App\Filament\Resources\Procurement\Ai\DatasetExamples\DatasetExampleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDatasetExamples extends ListRecords
{
    protected static string $resource = DatasetExampleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
