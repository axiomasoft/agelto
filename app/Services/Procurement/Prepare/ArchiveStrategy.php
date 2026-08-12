<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Actions\Procurement\Document\MarkDocumentUnprocessableAction;
use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Procurement\Prepare\Archive\ArchiveEntryValidator;
use App\Services\Procurement\Prepare\Archive\ArchiveExtractor;
use App\Services\Procurement\Prepare\Archive\ArchivePasswordProtectedException;
use App\Services\Procurement\Prepare\Archive\ArchiveUnsupportedException;
use App\Services\Procurement\Prepare\Archive\ArchiveValidationException;
use App\Services\Storage\FileLocationResolver;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Распаковка ZIP/7z/RAR (P3.6, D24 — `unar`, `unrar` из non-free не подключается) →
 * дочерние `Document`+`DocumentVersion` с `parent_version_id`; дети создаются сразу
 * `downloaded` (контент уже на диске, `source_url` NULL) и идут по пайплайну с prepare
 * (Code Guidance P4.2, канон v1 `Old/.../Strategies/ArchiveStrategy.php`). Формат-специфика —
 * в {@see ArchiveExtractor}; здесь — guard'ы бомбы, фильтр безопасных имён и ОДИН путь индексации
 * дочерних документов для всех трёх форматов.
 *
 * Zip-бомба (Code Guidance P4.2) проверяется ДО распаковки по двум осям: вложенность
 * архив-в-архиве по цепочке `parent_version_id` версии (`max_depth`) и заявленный ЛИСТИНГОМ
 * (без распаковки) число файлов/суммарный uncompressed-размер (`max_files`/`max_total_mb`).
 */
final readonly class ArchiveStrategy implements PreparationStrategy
{
    public function __construct(
        private DocumentStoreRepository $storeRepository,
        private MarkDocumentUnprocessableAction $markUnprocessable,
        private ProcurementStoreRepository $procurementStore,
        private ArchiveExtractor $extractor,
        private ArchiveEntryValidator $entryValidator,
        private FileLocationResolver $locationResolver,
    ) {}

    /**
     * Представления версии-архива не производит (файлы уезжают в ДОЧЕРНИЕ документы), но исход
     * терминален по существу: `true` = архив обработан, цепочка дальше не идёт. Терминальные
     * исходы (бомба/пароль/неподдержанный формат) — `unprocessable` ставится здесь же (D28),
     * возврат `false` при этом только сигнализирует «представления нет».
     */
    public function process(Document $document, DocumentVersion $version): PreparationResult
    {
        $budget = ArchiveBudget::fromConfig();
        if ($this->nestingDepth($version) >= $budget->maxDepth) {
            $this->markUnprocessable($document, UnprocessableReason::ZipBomb, 'превышена допустимая глубина вложенности архивов');

            return PreparationResult::Unprocessable;
        }

        $diskName = $this->locationResolver->currentDocumentsDisk();
        $disk = Storage::disk($diskName);
        $localArchive = tempnam(sys_get_temp_dir(), 'archive_');
        if ($localArchive === false) {
            throw new \RuntimeException('не удалось создать временный файл архива');
        }

        try {
            file_put_contents($localArchive, $disk->get($version->storage_path));

            try {
                $entries = $this->entryValidator->validate($this->extractor->listEntries($localArchive, $version->extension), $budget);
            } catch (ArchivePasswordProtectedException $e) {
                $this->markUnprocessable($document, UnprocessableReason::PasswordProtected, $e->getMessage());

                return PreparationResult::Unprocessable;
            } catch (ArchiveUnsupportedException $e) {
                $this->markUnprocessable($document, UnprocessableReason::ArchiveUnsupported, $e->getMessage());

                return PreparationResult::Unprocessable;
            } catch (ArchiveValidationException $e) {
                $this->markUnprocessable($document, UnprocessableReason::ZipBomb, $e->getMessage());

                return PreparationResult::Unprocessable;
            }

            try {
                $this->extractChildren($document, $version, $localArchive, $entries, $budget, $disk, $diskName);
            } catch (ArchivePasswordProtectedException $e) {
                $this->markUnprocessable($document, UnprocessableReason::PasswordProtected, $e->getMessage());

                return PreparationResult::Unprocessable;
            } catch (ArchiveUnsupportedException $e) {
                $this->markUnprocessable($document, UnprocessableReason::ArchiveUnsupported, $e->getMessage());

                return PreparationResult::Unprocessable;
            } catch (ArchiveValidationException $e) {
                $this->markUnprocessable($document, UnprocessableReason::ZipBomb, $e->getMessage());

                return PreparationResult::Unprocessable;
            }
        } finally {
            if (file_exists($localArchive) && ! unlink($localArchive)) {
                throw new \RuntimeException('не удалось удалить временный файл архива');
            }
        }

        return PreparationResult::ExpandedContainer;
    }

    /** @param list<ArchiveEntry> $entries */
    private function extractChildren(Document $document, DocumentVersion $version, string $localArchive, array $entries, ArchiveBudget $budget, Filesystem $disk, string $diskName): void
    {
        $tempFolder = 'tmp_extract/'.Str::uuid();
        $extractPath = storage_path('app/'.$tempFolder);

        try {
            $totalBytes = 0;
            foreach ($entries as $entry) {
                if ($entry->isDirectory) {
                    continue;
                }
                $targetPath = $extractPath.'/'.$entry->path;
                $directory = dirname($targetPath);
                if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new \RuntimeException("не удалось создать временную директорию: {$directory}");
                }
                $this->extractor->extractEntry($localArchive, $version->extension, $entry, $targetPath, $budget, $totalBytes);
            }

            foreach ($entries as $entry) {
                if (! $entry->isDirectory) {
                    $this->indexChild($document, $version, $entry->path, $extractPath.'/'.$entry->path, $disk, $diskName);
                }
            }
        } finally {
            File::deleteDirectory($extractPath);
        }
    }

    /**
     * Идемпотентность ключуется на существование `DocumentVersion` (не `Document`) —
     * ревью-находка P4 HIGH: без DB-транзакции child-`Document` мог закоммититься раньше
     * версии (например, `$disk->put` в `createVersion()` падал транзиентно на S3/minio),
     * а гард по `findByExternalId(Document)` находил осиротевший child при ретрае и делал
     * `return` раньше создания версии — версия не создавалась НИКОГДА, смета внутри архива
     * терялась без пути восстановления. Fix: сам S3 `put()` (идемпотентен по hash — `store()`
     * не перезаписывает существующий по пути) остаётся ВНЕ транзакции (внешний I/O не должен
     * держать DB-транзакцию открытой), а child+version+event — единая DB-транзакция.
     */
    private function indexChild(Document $document, DocumentVersion $version, string $relativePath, string $localPath, Filesystem $disk, string $diskName): void
    {
        $externalId = $version->id.':'.$relativePath;

        $existingChild = $this->storeRepository->findByExternalId($document->procurement_id, $externalId);
        if ($existingChild !== null && $this->storeRepository->latestVersion($existingChild->id) !== null) {
            return;
        }

        $content = file_get_contents($localPath);
        $hash = hash('sha256', $content);
        $storagePath = $this->store($disk, $hash, $content);

        DB::transaction(function () use ($document, $externalId, $relativePath, $content, $hash, $storagePath, $diskName, $version): void {
            $child = $this->storeRepository->findByExternalId($document->procurement_id, $externalId)
                ?? $this->storeRepository->create([
                    'procurement_id' => $document->procurement_id,
                    'platform_id' => $document->platform_id,
                    'external_id' => $externalId,
                    'title' => basename($relativePath),
                    'kind' => null,
                    'source_url' => null,
                    'status' => DocumentStatus::Downloaded,
                ]);

            $childVersion = $this->storeRepository->createVersion([
                'document_id' => $child->id,
                'content_hash' => $hash,
                'size_bytes' => strlen($content),
                'filename' => basename($relativePath),
                'extension' => pathinfo($relativePath, PATHINFO_EXTENSION),
                'mime' => $this->mimeOf($content),
                'storage_path' => $storagePath,
                'disk' => $diskName,
                'downloaded_at' => now(),
                'parent_version_id' => $version->id,
            ]);

            if ($document->procurement_id !== null) {
                $this->procurementStore->storeEvent(
                    procurementId: $document->procurement_id,
                    eventType: ProcurementEventType::DocumentAdded,
                    occurredAt: now(),
                    entityRef: ['document_id' => $child->id, 'document_version_id' => $childVersion->id],
                );
            }
        });
    }

    private function store(Filesystem $disk, string $hash, string $content): string
    {
        $path = 'documents/'.substr($hash, 0, 2)."/{$hash}";

        if (! $disk->exists($path)) {
            $disk->put($path, $content);
        }

        return $path;
    }

    private function mimeOf(string $content): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? finfo_buffer($finfo, $content) : false;

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    private function nestingDepth(DocumentVersion $version): int
    {
        $depth = 0;
        $parentId = $version->parent_version_id;

        while ($parentId !== null) {
            $depth++;
            $parent = DocumentVersion::query()->find($parentId);
            $parentId = $parent?->parent_version_id;
        }

        return $depth;
    }

    private function markUnprocessable(Document $document, UnprocessableReason $reason, string $detail): void
    {
        $this->markUnprocessable->execute($document, $reason, $detail);
    }
}
