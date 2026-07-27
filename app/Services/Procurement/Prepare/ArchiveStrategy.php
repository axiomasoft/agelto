<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Enums\Procurement\DocumentStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Распаковка ZIP (RAR/7z — Pending Work, нужен бинарный тул, ТЗ 03 §3.3 Scope Excluded) →
 * дочерние `Document`+`DocumentVersion` с `parent_version_id`; дети создаются сразу
 * `downloaded` (контент уже на диске, `source_url` NULL) и идут по пайплайну с prepare
 * (Code Guidance P4.2, канон v1 `Old/.../Strategies/ArchiveStrategy.php`).
 *
 * Zip-бомба (Code Guidance P4.2) проверяется ДО распаковки по двум осям: вложенность
 * архив-в-архиве по цепочке `parent_version_id` версии (`max_depth`) и заявленные заголовками
 * ZipArchive (без decompress) число файлов/суммарный uncompressed-размер (`max_files`/`max_total_mb`).
 */
final readonly class ArchiveStrategy implements PreparationStrategy
{
    public function __construct(
        private DocumentStoreRepository $storeRepository,
        private ProcurementStoreRepository $procurementStore,
    ) {}

    public function canProcess(DocumentVersion $version): bool
    {
        return $version->extension === 'zip';
    }

    /**
     * Представления версии-архива не производит (файлы уезжают в ДОЧЕРНИЕ документы), но исход
     * терминален по существу: `true` = архив обработан, цепочка дальше не идёт. Zip-бомба —
     * `unprocessable` ставится здесь же (D28: терминальный по существу исход), возврат `false`
     * при этом только сигнализирует «представления нет».
     */
    public function process(Document $document, DocumentVersion $version): bool
    {
        if ($this->nestingDepth($version) >= (int) config('ai.prepare.archive.max_depth', 2)) {
            $this->markUnprocessable($document, 'превышена допустимая глубина вложенности архивов');

            return false;
        }

        $disk = Storage::disk((string) config('crawler.documents_disk'));
        $localZip = tempnam(sys_get_temp_dir(), 'zip_');

        try {
            file_put_contents($localZip, $disk->get($version->storage_path));

            $zip = new ZipArchive;
            if ($zip->open($localZip) !== true) {
                throw new RuntimeException("Не удалось открыть архив: {$version->storage_path}");
            }

            [$fileCount, $totalBytes, $entries] = $this->inspect($zip);

            if ($fileCount > (int) config('ai.prepare.archive.max_files', 200)
                || $totalBytes > (int) config('ai.prepare.archive.max_total_mb', 500) * 1024 * 1024) {
                $zip->close();
                $this->markUnprocessable($document, "файлов={$fileCount}, суммарно={$totalBytes} байт");

                return false;
            }

            $this->extractChildren($document, $version, $zip, $entries, $disk);

            $zip->close();
        } finally {
            @unlink($localZip);
        }

        return true;
    }

    /**
     * @return array{0: int, 1: int, 2: list<string>}
     */
    private function inspect(ZipArchive $zip): array
    {
        $fileCount = 0;
        $totalBytes = 0;
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) $stat['name'];

            if (str_ends_with($name, '/') || str_starts_with(basename($name), '.') || ! $this->isSafeEntryName($name)) {
                continue;
            }

            $fileCount++;
            $totalBytes += (int) $stat['size'];
            $entries[] = $name;
        }

        return [$fileCount, $totalBytes, $entries];
    }

    /**
     * @param  list<string>  $entries
     */
    private function extractChildren(Document $document, DocumentVersion $version, ZipArchive $zip, array $entries, Filesystem $disk): void
    {
        $tempFolder = 'tmp_extract/'.Str::uuid();
        $extractPath = storage_path('app/'.$tempFolder);

        try {
            $zip->extractTo($extractPath, $entries);

            foreach ($entries as $relativePath) {
                $this->indexChild($document, $version, $relativePath, $extractPath.'/'.$relativePath, $disk);
            }
        } finally {
            File::deleteDirectory($extractPath);
        }
    }

    /**
     * Защита в глубину от path traversal в именах entry архива (не доверяем содержимому
     * недоверенных ZIP из закупок): отбрасываем абсолютные пути и `..`-сегменты до extractTo().
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

    private function markUnprocessable(Document $document, string $detail): void
    {
        $this->storeRepository->update($document, [
            'status' => DocumentStatus::Unprocessable,
            'last_error' => 'unprocessable:'.UnprocessableReason::ZipBomb->value.": {$detail}",
        ]);
    }
}
