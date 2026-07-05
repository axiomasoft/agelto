<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentAnalysis\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\DocumentAnalysis\DocumentAnalysisResource;
use App\Repositories\DocumentAnalysis\DocumentAnalysisRepository;

final class CreateDocumentAnalysis extends CreateRecord
{
    protected static string $resource = DocumentAnalysisResource::class;

    protected ?string $pendingDocumentPath = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingDocumentPath = $this->documentAnalysisRepository()->extractDocumentPath($data['document'] ?? null);
        unset($data['document']);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->pendingDocumentPath !== null) {
            $this->documentAnalysisRepository()->attachDocumentFromPath($this->getRecord(), $this->pendingDocumentPath);
            $this->pendingDocumentPath = null;
        }
    }

    private function documentAnalysisRepository(): DocumentAnalysisRepository
    {
        return app(DocumentAnalysisRepository::class);
    }
}
