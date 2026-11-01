<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

use App\Services\Procurement\Prepare\Archive\ArchiveEntryPath;
use DateTimeImmutable;

final readonly class SourceDocumentMetadata
{
    public function __construct(
        public string $filename,
        public string $mime,
        public ?string $etag = null,
        public ?DateTimeImmutable $lastModified = null,
        public ?int $parentVersionId = null,
        public ?string $normalizedEntryPath = null,
        public ?int $childOrdinal = null,
    ) {
        if ($filename === '' || strlen($filename) > 1024 || preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~Di', $mime) !== 1
            || (($parentVersionId === null) !== ($normalizedEntryPath === null)) || (($parentVersionId === null) !== ($childOrdinal === null))
            || ($parentVersionId !== null && $parentVersionId < 1) || ($childOrdinal !== null && $childOrdinal < 0)) {
            throw new \InvalidArgumentException('Invalid source document metadata or lineage.');
        }
        if ($normalizedEntryPath !== null && ArchiveEntryPath::normalize($normalizedEntryPath, 1024) !== $normalizedEntryPath) {
            throw new \InvalidArgumentException('Archive entry path must already be normalized.');
        }
    }
}
