<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

final class ArchiveEntryPath
{
    public static function normalize(string $path, int $maxLength): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new ArchiveValidationException('недопустимый путь записи архива');
        }

        $normalized = str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
        if ($normalized === '' || strlen($normalized) > $maxLength) {
            throw new ArchiveValidationException('пустой или слишком длинный путь записи архива');
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new ArchiveValidationException('путь записи архива содержит запрещённый сегмент');
            }
        }

        return $normalized;
    }
}
