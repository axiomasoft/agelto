<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Document;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\Document\StoredDocumentWriter;
use App\Services\Storage\FileLocationResolver;
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
        $content = file_get_contents($localPath);
        if ($content === false) {
            throw new \RuntimeException("Не удалось прочитать извлечённую запись архива: {$entryPath}");
        }

        $hash = hash(algo: 'sha256', data: $content);
        $storagePath = $this->store(content: $content, hash: $hash);

        return app(StoredDocumentWriter::class)->archiveEntry($parent, $parentVersion, $entryPath, $childOrdinal, [
            'content_hash' => $hash, 'size_bytes' => strlen($content), 'filename' => basename($entryPath),
            'mime' => $this->mimeOf($content), 'storage_path' => $storagePath, 'disk' => $this->locations->currentDocumentsDisk(),
        ]);
    }

    public function markParentExpanded(Document $parent): void
    {
        DB::transaction(function () use ($parent): void {
            $this->documents->update(document: $parent, attributes: ['status' => DocumentStatus::Expanded]);
        });
    }

    private function store(string $content, string $hash): string
    {
        $path = 'documents/'.substr($hash, 0, 2)."/{$hash}";
        $disk = Storage::disk($this->locations->currentDocumentsDisk());

        $alreadyStored = $disk->exists($path);

        if (! $alreadyStored && $disk->put($path, $content) !== true) {
            throw new \RuntimeException("Не удалось сохранить запись архива в object storage: {$path}");
        }

        $stored = $alreadyStored ? $disk->get($path) : $content;

        if (! is_string($stored) || ! hash_equals($hash, hash('sha256', $stored))) {
            throw new \RuntimeException("Object storage вернул неполную или другую запись архива: {$path}");
        }

        return $path;
    }

    private function mimeOf(string $content): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? finfo_buffer($finfo, $content) : false;

        return is_string($mime) ? $mime : 'application/octet-stream';
    }
}
