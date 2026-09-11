<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Document;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Storage\FileLocationResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class ExpandArchiveEntryAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private ProcurementStoreRepository $procurements,
        private FileLocationResolver $locations,
    ) {}

    public function execute(Document $parent, DocumentVersion $parentVersion, string $entryPath, string $localPath): DocumentVersion
    {
        $content = file_get_contents($localPath);
        if ($content === false) {
            throw new \RuntimeException("Не удалось прочитать извлечённую запись архива: {$entryPath}");
        }

        $hash = hash(algo: 'sha256', data: $content);
        $storagePath = $this->store(content: $content, hash: $hash);

        try {
            return DB::transaction(function () use ($parent, $parentVersion, $entryPath, $content, $hash, $storagePath): DocumentVersion {
                $existing = $this->documents->findArchiveEntryVersion(
                    parentVersionId: $parentVersion->id,
                    archiveEntryPath: $entryPath,
                );
                if ($existing !== null) {
                    return $existing;
                }

                $child = $this->documents->findByExternalId(
                    procurementId: $parent->procurement_id,
                    externalId: $this->externalId(parentVersion: $parentVersion, entryPath: $entryPath),
                ) ?? $this->documents->create([
                    'procurement_id' => $parent->procurement_id,
                    'platform_id' => $parent->platform_id,
                    'external_id' => $this->externalId(parentVersion: $parentVersion, entryPath: $entryPath),
                    'title' => basename($entryPath),
                    'kind' => null,
                    'source_url' => null,
                    'status' => DocumentStatus::Downloaded,
                ]);

                $childVersion = $this->documents->createVersion([
                    'document_id' => $child->id,
                    'content_hash' => $hash,
                    'size_bytes' => strlen($content),
                    'filename' => basename($entryPath),
                    'extension' => pathinfo($entryPath, PATHINFO_EXTENSION),
                    'mime' => $this->mimeOf(content: $content),
                    'storage_path' => $storagePath,
                    'disk' => $this->locations->currentDocumentsDisk(),
                    'downloaded_at' => now(),
                    'parent_version_id' => $parentVersion->id,
                    'archive_entry_path' => $entryPath,
                ]);

                if ($parent->procurement_id !== null) {
                    $this->procurements->storeEvent(
                        procurementId: $parent->procurement_id,
                        eventType: ProcurementEventType::DocumentAdded,
                        occurredAt: now(),
                        entityRef: ['document_id' => $child->id, 'document_version_id' => $childVersion->id],
                    );
                }

                PrepareDocumentJob::dispatch(documentId: $child->id)->afterCommit();

                return $childVersion;
            });
        } catch (QueryException $exception) {
            $existing = $this->documents->findArchiveEntryVersion(
                parentVersionId: $parentVersion->id,
                archiveEntryPath: $entryPath,
            );
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    public function markParentExpanded(Document $parent): void
    {
        DB::transaction(function () use ($parent): void {
            $this->documents->update(document: $parent, attributes: ['status' => DocumentStatus::Expanded]);
        });
    }

    private function externalId(DocumentVersion $parentVersion, string $entryPath): string
    {
        return "{$parentVersion->id}:{$entryPath}";
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
