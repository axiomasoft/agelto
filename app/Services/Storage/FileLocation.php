<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Неизменяемая пара disk/path файла. Файловые операции остаются у consumers,
 * чтобы объект-значение не зависел от Flysystem.
 */
final readonly class FileLocation
{
    public function __construct(
        public string $disk,
        public string $path,
    ) {}
}
