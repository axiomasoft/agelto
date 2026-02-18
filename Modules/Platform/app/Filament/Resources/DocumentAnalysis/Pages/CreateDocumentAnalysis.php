<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\DocumentAnalysis\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;
use Platform\Filament\Resources\DocumentAnalysis\DocumentAnalysisResource;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;

final class CreateDocumentAnalysis extends CreateRecord
{
    protected static string $resource = DocumentAnalysisResource::class;

    protected ?string $pendingDocumentPath = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingDocumentPath = $this->extractDocumentPath($data['document'] ?? null);
        unset($data['document']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->attachPendingDocument($this->getRecord());
    }

    private function extractDocumentPath(mixed $document): ?string
    {
        if (empty($document)) {
            return null;
        }
        if (is_array($document)) {
            $document = $document[0] ?? null;
        }
        $path = (string) $document;
        if ($path === '' || str_starts_with($path, 'http')) {
            return null;
        }

        return $path;
    }

    private function attachPendingDocument(DocumentAnalysis $record): void
    {
        if ($this->pendingDocumentPath === null) {
            return;
        }
        $record->clearMediaCollection('document');
        $disk = config('app.filesystems.private_disk_name');
        $fullPath = Storage::disk($disk)->path($this->pendingDocumentPath);
        if (is_file($fullPath)) {
            $record->addMedia($fullPath)->toMediaCollection(
                'document',
                config('app.filesystems.private_disk_name')
            );
        }
        $this->pendingDocumentPath = null;
    }
}
