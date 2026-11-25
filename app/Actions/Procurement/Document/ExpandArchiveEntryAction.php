<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Document;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\Document\StoredDocumentWriter;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\MimeDetector;
use App\Support\Files\ShardedBlobPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class ExpandArchiveEntryAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private FileLocationResolver $locations,
    ) {}

    public function execute(Document $parent, DocumentVersion $parentVersion, string $entryPath, string $localPath, int $childOrdinal = 0): DocumentVersion
    {
        $stream = is_file($localPath) ? @fopen($localPath, 'rb') : false;
        if (! is_resource($stream)) {
            throw new \RuntimeException("Не удалось прочитать извлечённую запись архива: {$entryPath}");
        }

        try {
            $prefix = fread($stream, 8192);
            if ($prefix === false) {
                throw new \RuntimeException("Не удалось прочитать извлечённую запись архива: {$entryPath}");
            }

            $context = hash_init('sha256');
            hash_update($context, $prefix);
            $remainingBytes = hash_update_stream($context, $stream);

            $sizeBytes = strlen($prefix) + $remainingBytes;
            $hash = hash_final($context);
            $mime = MimeDetector::of($prefix);
            $diskName = $this->locations->currentDocumentsDisk();
            $storagePath = $this->store(diskName: $diskName, stream: $stream, hash: $hash, sizeBytes: $sizeBytes);
        } finally {
            fclose($stream);
        }

        return app(StoredDocumentWriter::class)->archiveEntry($parent, $parentVersion, $entryPath, $childOrdinal, [
            'content_hash' => $hash, 'size_bytes' => $sizeBytes, 'filename' => basename($entryPath),
            'mime' => $mime, 'storage_path' => $storagePath, 'disk' => $diskName,
        ]);
    }

    public function markParentExpanded(Document $parent): void
    {
        DB::transaction(function () use ($parent): void {
            $this->documents->update(document: $parent, attributes: ['status' => DocumentStatus::Expanded]);
        });
    }

    /** @param resource $stream */
    private function store(string $diskName, mixed $stream, string $hash, int $sizeBytes): string
    {
        $path = ShardedBlobPath::for($hash);
        $disk = Storage::disk($diskName);
        $lockPath = sys_get_temp_dir().'/agelto_archive_blob_'.$hash.'.lock';
        $lock = @fopen($lockPath, 'c');
        if (is_resource($lock)) {
            @flock($lock, LOCK_EX);
        }

        try {
            if (! $disk->exists($path)) {
                if (! @rewind($stream)) {
                    throw new \RuntimeException("Не удалось перемотать поток записи архива: {$path}");
                }
                if ($disk->put($path, $stream) !== true) {
                    throw new \RuntimeException("Не удалось сохранить запись архива в object storage: {$path}");
                }
            }

            if (! $this->verifyStored(diskName: $diskName, path: $path, expectedHash: $hash, expectedSize: $sizeBytes)) {
                throw new \RuntimeException("Object storage вернул неполную или другую запись архива: {$path}");
            }

            return $path;
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function verifyStored(string $diskName, string $path, string $expectedHash, int $expectedSize): bool
    {
        $stored = Storage::disk($diskName)->readStream($path);
        if (! is_resource($stored)) {
            return false;
        }

        try {
            $context = hash_init('sha256');
            $actualSize = 0;
            while (! feof($stored)) {
                $chunk = fread($stored, 8192);
                if ($chunk === false || ($chunk === '' && ! feof($stored))) {
                    return false;
                }
                $actualSize += strlen($chunk);
                if ($actualSize > $expectedSize) {
                    return false;
                }
                hash_update($context, $chunk);
            }

            return $actualSize === $expectedSize && hash_equals($expectedHash, hash_final($context));
        } finally {
            fclose($stored);
        }
    }
}
