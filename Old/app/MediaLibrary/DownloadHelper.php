<?php

declare(strict_types=1);

namespace App\MediaLibrary;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class DownloadHelper
{
    /**
     * Возвращает URL для скачивания/просмотра файла.
     * Для приватного диска — маршрут media.download, иначе — публичный URL медиа.
     */
    public static function url(?Media $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $privateDisk = config('app.filesystems.private_disk_name');
        $isOnPrivateDisk = $file->disk === $privateDisk;

        return $isOnPrivateDisk
            ? route('media.download', [
                'uuid' => $file->uuid,
                'collection_name' => $file->collection_name,
            ])
            : $file->getUrl();
    }
}
