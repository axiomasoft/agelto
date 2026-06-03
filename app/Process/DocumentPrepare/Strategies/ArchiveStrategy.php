<?php

declare(strict_types=1);

namespace App\Process\DocumentPrepare\Strategies;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use ZipArchive;

final class ArchiveStrategy implements PreparationStrategy
{
    public function canProcess(Media $media): bool
    {
        return $media->extension === 'zip';
    }

    public function process(DocumentAnalysis $analysis, Media $media): void
    {
        $zip = new ZipArchive();
        if ($zip->open($media->getPath()) === true) {
            $tempFolder = 'temp_extract/' . Str::uuid();
            $storagePath = storage_path('app/' . $tempFolder);
            $zip->extractTo($storagePath);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $fileName = $zip->getNameIndex($i);
                $filePath = $storagePath . '/' . $fileName;

                if (is_dir($filePath) || str_starts_with(basename($fileName), '.')) {
                    continue;
                }

                $child = DocumentAnalysis::create([
                    'parent_id' => $analysis->id,
                    'extension' => pathinfo($fileName, PATHINFO_EXTENSION),
                    'model_type' => $analysis->model_type,
                    'model_id' => $analysis->model_id,
                ]);

                $child->addMedia($filePath)->toMediaCollection(DocumentAnalysis::COLLECTION_DOCUMENT_SOURCE, config('app.filesystems.private_disk_name'));
            }
            $zip->close();
            Storage::deleteDirectory($tempFolder);
        }
    }
}
