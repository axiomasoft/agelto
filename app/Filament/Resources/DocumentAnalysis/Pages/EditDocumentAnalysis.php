<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentAnalysis\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\DocumentAnalysis\DocumentAnalysisResource;
use App\Repositories\DocumentAnalysis\DocumentAnalysisRepository;

final class EditDocumentAnalysis extends EditRecord
{
    protected static string $resource = DocumentAnalysisResource::class;

    protected ?string $pendingDocumentPath = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $media = $this->getRecord()->getFirstMedia('document');
        if ($media !== null) {
            $data['document'] = $media->getPathRelativeToRoot();
            $data['extension'] = pathinfo($media->file_name, PATHINFO_EXTENSION);
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingDocumentPath = $this->documentAnalysisRepository()->extractDocumentPath($data['document'] ?? null);
        unset($data['document']);

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->pendingDocumentPath !== null) {
            $this->documentAnalysisRepository()->attachDocumentFromPath($this->getRecord(), $this->pendingDocumentPath);
            $this->pendingDocumentPath = null;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    private function documentAnalysisRepository(): DocumentAnalysisRepository
    {
        return app(DocumentAnalysisRepository::class);
    }
}
