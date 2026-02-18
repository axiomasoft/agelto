<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MediaLibraryController extends Controller
{
    /**
     * Скачивание файла из MediaLibrary по UUID и collection_name.
     */
    public function download(string $uuid, string $collectionName): StreamedResponse|Response
    {
        $media = Media::where('uuid', $uuid)
            ->where('collection_name', $collectionName)
            ->firstOrFail();

        // Проверяем, что файл существует на диске
        $disk = \Illuminate\Support\Facades\Storage::disk($media->disk);
        $pathGenerator = app(\App\MediaLibrary\Support\PathGenerator::class);
        $mediaPath = $pathGenerator->getPath($media);

        // Используем name (хэш) для доступа к файлу на диске, если файл был сохранен с хэшем
        // Иначе используем file_name (оригинальное имя)
        $extension = pathinfo($media->file_name, PATHINFO_EXTENSION);
        $fileNameOnDisk = $media->name.'.'.$extension;
        $filePath = $mediaPath.$fileNameOnDisk;

        // Если файл с хэшем не найден, пробуем с оригинальным именем
        if (! $disk->exists($filePath)) {
            $filePath = $mediaPath.$media->file_name;
            if (! $disk->exists($filePath)) {
                abort(404, 'File not found');
            }
        }

        // Возвращаем файл для скачивания с оригинальным именем
        return $disk->download($filePath, $media->file_name);
    }
}
