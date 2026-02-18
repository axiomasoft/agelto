<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\DocumentAnalysis\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Platform\Filament\Resources\DocumentAnalysis\DocumentAnalysisResource;

final class ListDocumentAnalyses extends ListRecords
{
    protected static string $resource = DocumentAnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
