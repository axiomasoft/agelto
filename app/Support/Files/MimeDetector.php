<?php

declare(strict_types=1);

namespace App\Support\Files;

/**
 * Технический слой, доменов не знает (скилл `laravel-structure`, ось Technical axis) — общий
 * `mimeOf()` (§5 `root/service-layer-map.md`).
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
