<?php

declare(strict_types=1);

namespace App\Repositories\DocumentAnalysis;

use Illuminate\Support\Facades\Storage;
use App\Models\DocumentAnalysis\DocumentAnalysis;

final class DocumentAnalysisRepository
{
    /**
     * Извлекает путь к файлу из значения поля загрузки (строка или массив).
     */
    public function extractDocumentPath(mixed $document): ?string
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

    /**
     * Привязывает файл по относительному пути к записи в медиа-коллекцию «document».
     */
    public function attachDocumentFromPath(DocumentAnalysis $record, string $path): void
    {
        $record->clearMediaCollection('document');
        $disk = config('app.filesystems.private_disk_name');
        $fullPath = Storage::disk($disk)->path($path);
        if (is_file($fullPath)) {
            $record->addMedia($fullPath)->toMediaCollection(
                'document',
                config('app.filesystems.private_disk_name')
            );
        }
    }
}
