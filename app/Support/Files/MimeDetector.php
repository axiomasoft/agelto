<?php

declare(strict_types=1);

namespace App\Support\Files;

/**
 * Определение MIME по содержимому без доменных зависимостей.
 */
final class MimeDetector
{
    public static function of(string $content): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? finfo_buffer($finfo, $content) : false;

        return is_string($mime) ? $mime : 'application/octet-stream';
    }
}
