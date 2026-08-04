<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Procurement\Prepare\Archive\ArchiveExtractor;
use App\Services\Procurement\Prepare\Archive\ArchivePasswordProtectedException;
use App\Services\Procurement\Prepare\Archive\ArchiveUnsupportedException;
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
        private ProcurementStoreRepository $procurementStore,
        private ArchiveExtractor $extractor,
    ) {}

    public function canProcess(DocumentVersion $version): bool
    {
        return in_array($version->extension, ['zip', '7z', 'rar'], true);
    }

    /**
     * Представления версии-архива не производит (файлы уезжают в ДОЧЕРНИЕ документы), но исход
     * терминален по существу: `true` = архив обработан, цепочка дальше не идёт. Терминальные
     * исходы (бомба/пароль/неподдержанный формат) — `unprocessable` ставится здесь же (D28),
     * возврат `false` при этом только сигнализирует «представления нет».
     */
    public function process(Document $document, DocumentVersion $version): bool
    {
        if ($this->nestingDepth($version) >= (int) config('ai.prepare.archive.max_depth', 2)) {
            $this->markUnprocessable($document, UnprocessableReason::ZipBomb, 'превышена допустимая глубина вложенности архивов');

            return false;
        }

        $disk = Storage::disk((string) config('crawler.documents_disk'));
        $localArchive = tempnam(sys_get_temp_dir(), 'archive_');

        try {
            file_put_contents($localArchive, $disk->get($version->storage_path));

            try {
                $listing = $this->extractor->listEntries($localArchive, $version->extension);
            } catch (ArchivePasswordProtectedException $e) {
                $this->markUnprocessable($document, UnprocessableReason::PasswordProtected, $e->getMessage());

                return false;
            } catch (ArchiveUnsupportedException $e) {
                $this->markUnprocessable($document, UnprocessableReason::ArchiveUnsupported, $e->getMessage());

                return false;
            }

            [$fileCount, $totalBytes, $entries] = $this->safeEntries($listing);

            if ($fileCount > (int) config('ai.prepare.archive.max_files', 200)
                || $totalBytes > (int) config('ai.prepare.archive.max_total_mb', 500) * 1024 * 1024) {
                $this->markUnprocessable($document, UnprocessableReason::ZipBomb, "файлов={$fileCount}, суммарно={$totalBytes} байт");

                return false;
            }

            $this->extractChildren($document, $version, $localArchive, $entries, $disk);
        } finally {
            @unlink($localArchive);
        }

        return true;
    }

    /**
     * @param  list<array{name: string, size: int}>  $listing
     * @return array{0: int, 1: int, 2: list<string>}
     */
    private function safeEntries(array $listing): array
    {
        $fileCount = 0;
        $totalBytes = 0;
        $entries = [];

        foreach ($listing as $entry) {
            $name = $entry['name'];

            if (str_ends_with($name, '/') || str_starts_with(basename($name), '.') || ! $this->isSafeEntryName($name)) {
                continue;
            }

            $fileCount++;
            $totalBytes += $entry['size'];
            $entries[] = $name;
        }

        return [$fileCount, $totalBytes, $entries];
    }

    /**
     * @param  list<string>  $entries
     */
    private function extractChildren(Document $document, DocumentVersion $version, string $localArchive, array $entries, Filesystem $disk): void
    {
        $tempFolder = 'tmp_extract/'.Str::uuid();
        $extractPath = storage_path('app/'.$tempFolder);

        try {
            $this->extractor->extract($localArchive, $version->extension, $extractPath, $entries);

            foreach ($entries as $relativePath) {
                $this->indexChild($document, $version, $relativePath, $extractPath.'/'.$relativePath, $disk);
            }
        } finally {
            File::deleteDirectory($extractPath);
        }
    }

    /**
     * Защита в глубину от path traversal в именах entry архива (не доверяем содержимому
     * недоверенных архивов из закупок) — для ВСЕХ трёх форматов: отбрасываем абсолютные пути
     * и `..`-сегменты до распаковки.
     */
    private function isSafeEntryName(string $name): bool
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0")) {
            return false;
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
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
    private function indexChild(Document $document, DocumentVersion $version, string $relativePath, string $localPath, Filesystem $disk): void
    {
        $externalId = $version->id.':'.$relativePath;

        $existingChild = $this->storeRepository->findByExternalId($document->procurement_id, $externalId);
        if ($existingChild !== null && $this->storeRepository->latestVersion($existingChild->id) !== null) {
            return;
        }

        $content = file_get_contents($localPath);
        $hash = hash('sha256', $content);
        $storagePath = $this->store($disk, $hash, $content);

        DB::transaction(function () use ($document, $externalId, $relativePath, $content, $hash, $storagePath, $version): void {
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
        $this->storeRepository->update($document, [
            'status' => DocumentStatus::Unprocessable,
            'last_error' => 'unprocessable:'.$reason->value.": {$detail}",
        ]);
    }
}
