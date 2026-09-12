<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare\Archive;

/** Нормализованная metadata-запись hostile archive. */
final readonly class ArchiveEntry
{
    public function __construct(
        public string $path,
        public int $declaredSize,
        public ?int $compressedSize,
        public bool $isDirectory = false,
        public bool $isEncrypted = false,
        public string $type = 'file',
    ) {}
}
