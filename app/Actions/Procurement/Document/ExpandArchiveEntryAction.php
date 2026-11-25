<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Document;

use App\Dto\Procurement\Document\StoredBlobUpload;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\Document\BlobStore;
use App\Services\Procurement\Document\StoredDocumentWriter;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\MimeDetector;
use Illuminate\Support\Facades\DB;

final readonly class ExpandArchiveEntryAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private FileLocationResolver $locations,
        private BlobStore $blobs,
        private StoredDocumentWriter $writer,
    ) {}

    public function execute(Document $parent, DocumentVersion $parentVersion, string $entryPath, string $localPath, int $childOrdinal = 0): DocumentVersion
    {
        $stream = is_file($localPath) ? @fopen($localPath, 'rb') : false;
        if (! is_resource($stream)) {
            throw new \RuntimeException("Не удалось прочитать извлечённую запись архива: {$entryPath}");
        }

        try {
            $sizeBytes = fstat($stream)['size'] ?? null;
            $prefix = fread($stream, 8192);
            if ($sizeBytes === null || $prefix === false) {
                throw new \RuntimeException("Не удалось прочитать извлечённую запись архива: {$entryPath}");
            }

            $context = hash_init('sha256');
            hash_update($context, $prefix);
            $remainingBytes = hash_update_stream($context, $stream);

            if (strlen($prefix) + $remainingBytes !== $sizeBytes) {
                throw new \RuntimeException("Не удалось прочитать запись архива целиком: {$entryPath}");
            }

            $hash = new ContentHash(hex: hash_final($context));
            $mime = MimeDetector::of($prefix);
            $diskName = $this->locations->currentDocumentsDisk();
            $storagePath = $this->blobs->put(
                reservation: new StoredBlobUpload(disk: $diskName, hash: $hash, sizeBytes: $sizeBytes),
                source: $stream,
            );
        } finally {
            fclose($stream);
        }

        return $this->writer->archiveEntry(
            parent: $parent,
            parentVersion: $parentVersion,
            entryPath: $entryPath,
            ordinal: $childOrdinal,
            attributes: [
                'content_hash' => $hash->hex, 'size_bytes' => $sizeBytes, 'filename' => basename($entryPath),
                'mime' => $mime, 'storage_path' => $storagePath, 'disk' => $diskName,
            ],
        );
    }

    public function markParentExpanded(Document $parent): void
    {
        DB::transaction(function () use ($parent): void {
            $this->documents->update(document: $parent, attributes: ['status' => DocumentStatus::Expanded]);
        });
    }
}
