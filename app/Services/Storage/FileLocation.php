<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Диск + путь файла-носителя (P6.3, `research/07-storage-addressing.md` §4.1) — неизменяемый
 * объект-значение. `exists()`/`get()`/`url()` здесь не заводятся: VO с файловыми операциями
 * превращается в фасад Flysystem.
 */
final readonly class FileLocation
{
    public function __construct(
        public string $disk,
        public string $path,
    ) {}
}
