<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\DocumentAnalysis\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;
use Platform\Filament\Resources\DocumentAnalysis\DocumentAnalysisResource;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;

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
        $this->pendingDocumentPath = $this->extractDocumentPath($data['document'] ?? null);
        unset($data['document']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->attachPendingDocument($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
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
