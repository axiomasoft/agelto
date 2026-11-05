<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Actions\Procurement\Document\ExpandArchiveEntryAction;
use App\Actions\Procurement\Document\MarkDocumentUnprocessableAction;
use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Prepare\Archive\ArchiveEntryValidator;
use App\Services\Procurement\Prepare\Archive\ArchiveExtractor;
use App\Services\Procurement\Prepare\Archive\ArchivePasswordProtectedException;
use App\Services\Procurement\Prepare\Archive\ArchiveUnsupportedException;
use App\Services\Procurement\Prepare\Archive\ArchiveValidationException;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Проверяет и извлекает архив по одному entry. File I/O остаётся здесь, а durable child
 * lineage, event и after-commit dispatch принадлежат ExpandArchiveEntryAction.
 */
final readonly class ArchiveStrategy implements PreparationStrategy
{
    public function __construct(
        private ExpandArchiveEntryAction $expandArchiveEntry,
        private MarkDocumentUnprocessableAction $markUnprocessable,
        private ArchiveExtractor $extractor,
        private ArchiveEntryValidator $entryValidator,
        private FileLocationResolver $locationResolver,
    ) {}

    public function process(Document $document, DocumentVersion $version, ?float $deadlineAt = null): PreparationResult
    {
        $budget = ArchiveBudget::fromConfig();
        [$depth, $ancestorEntries, $ancestorBytes] = $this->lineageUsage($version);
        if ($depth >= $budget->maxDepth) {
            $this->markUnprocessable($document, UnprocessableReason::ZipBomb, 'превышена допустимая глубина вложенности архивов');

            return PreparationResult::Unprocessable;
        }
        if ($depth > 0) {
            $budget = new ArchiveBudget(
                maxDepth: $budget->maxDepth,
                maxEntries: max(0, $budget->maxEntries - $ancestorEntries),
                maxEntryBytes: $budget->maxEntryBytes,
                maxTotalBytes: max(0, $budget->maxTotalBytes - $ancestorBytes),
                maxCompressionRatio: $budget->maxCompressionRatio,
                maxPathLength: $budget->maxPathLength,
            );
        }

        $location = $this->locationResolver->forDocumentVersion($version);
        $localArchive = PipelineTemporaryFile::create(prefix: 'archive_', extension: $version->extension);

        try {
            try {
                $this->copyToLocal(Storage::disk($location->disk), $location->path, $localArchive, $budget, $deadlineAt);
                $entries = $this->entryValidator->validate($this->extractor->listEntries(archivePath: $localArchive, format: $version->extension, deadlineAt: $deadlineAt), $budget);
                $this->extractChildren($document, $version, $localArchive, $entries, $budget, $deadlineAt);
            } catch (ArchivePasswordProtectedException $exception) {
                $this->markUnprocessable($document, UnprocessableReason::PasswordProtected, $exception->getMessage());

                return PreparationResult::Unprocessable;
            } catch (ArchiveUnsupportedException $exception) {
                $this->markUnprocessable($document, UnprocessableReason::ArchiveUnsupported, $exception->getMessage());

                return PreparationResult::Unprocessable;
            } catch (ArchiveValidationException $exception) {
                $this->markUnprocessable($document, UnprocessableReason::ZipBomb, $exception->getMessage());

                return PreparationResult::Unprocessable;
            }
        } finally {
            if (file_exists($localArchive) && ! unlink($localArchive)) {
                throw new \RuntimeException('не удалось удалить временный файл архива');
            }
        }

        $this->expandArchiveEntry->markParentExpanded($document);

        return PreparationResult::ExpandedContainer;
    }

    /** @param list<ArchiveEntry> $entries */
    private function extractChildren(Document $document, DocumentVersion $version, string $localArchive, array $entries, ArchiveBudget $budget, ?float $deadlineAt): void
    {
        $extractPath = PipelineTemporaryFile::createDirectory(prefix: 'archive_extract_');

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

                $this->extractor->extractEntry(
                    archivePath: $localArchive,
                    format: $version->extension,
                    entry: $entry,
                    targetPath: $targetPath,
                    budget: $budget,
                    totalBytes: $totalBytes,
                    deadlineAt: $deadlineAt,
                );
            }

            foreach ($entries as $ordinal => $entry) {
                if ($entry->isDirectory) {
                    continue;
                }

                $this->expandArchiveEntry->execute(
                    parent: $document,
                    parentVersion: $version,
                    entryPath: $entry->path,
                    localPath: $extractPath.'/'.$entry->path,
                    childOrdinal: $ordinal,
                );
            }
        } finally {
            PipelineTemporaryFile::deleteDirectory($extractPath);
        }
    }

    private function copyToLocal(Filesystem $disk, string $storagePath, string $localPath, ArchiveBudget $budget, ?float $deadlineAt): void
    {
        $this->assertWithinDeadline($deadlineAt);
        $stream = $disk->readStream($storagePath);

        if (! is_resource($stream)) {
            $contents = $disk->get($storagePath);
            if (! is_string($contents)) {
                throw new \RuntimeException("Оригинал архива недоступен на диске: {$storagePath}");
            }
            if (strlen($contents) > $budget->maxTotalBytes) {
                throw new ArchiveValidationException('превышен суммарный размер архива');
            }
            if (file_put_contents($localPath, $contents) === false) {
                throw new \RuntimeException("Не удалось записать временный файл архива: {$localPath}");
            }

            return;
        }

        $target = fopen($localPath, 'wb');
        if (! is_resource($target)) {
            fclose($stream);
            throw new \RuntimeException("Не удалось открыть временный файл архива: {$localPath}");
        }

        try {
            $written = 0;
            while (! feof($stream)) {
                $this->assertWithinDeadline($deadlineAt);
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    throw new \RuntimeException("Ошибка чтения потока архива: {$storagePath}");
                }
                if ($chunk === '') {
                    continue;
                }
                $written += strlen($chunk);
                if ($written > $budget->maxTotalBytes) {
                    throw new ArchiveValidationException('превышен суммарный размер архива');
                }
                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException("Не удалось записать временный файл архива: {$localPath}");
                }
            }
        } finally {
            fclose($target);
            fclose($stream);
        }
    }

    private function assertWithinDeadline(?float $deadlineAt): void
    {
        $reserve = config('documents.format_tools.persistence_seconds', 120);
        $reserveSeconds = is_int($reserve) && $reserve >= 0 ? $reserve : 120;

        if ($deadlineAt !== null && microtime(true) >= $deadlineAt - $reserveSeconds) {
            throw new ArchiveValidationException('единый deadline распаковки исчерпан');
        }
    }

    /** @return array{int, int, int} */
    private function lineageUsage(DocumentVersion $version): array
    {
        $depth = 0;
        $rootVersionId = $version->id;
        $parentId = $version->parent_version_id;

        while ($parentId !== null) {
            $depth++;
            $rootVersionId = $parentId;
            $parent = DocumentVersion::query()->find($parentId);
            $parentId = $parent?->parent_version_id;
        }

        if ($depth === 0) {
            return [0, 0, 0];
        }

        $count = 0;
        $bytes = 0;
        $frontier = [$rootVersionId];
        $visited = [$rootVersionId => true];

        while ($frontier !== []) {
            $children = DocumentVersion::query()
                ->whereIn('parent_version_id', $frontier)
                ->get(['id', 'size_bytes']);
            $frontier = [];

            foreach ($children as $child) {
                if (isset($visited[$child->id])) {
                    continue;
                }
                $visited[$child->id] = true;
                $count++;
                $bytes += (int) $child->size_bytes;

                if ($child->id !== $version->id) {
                    $frontier[] = $child->id;
                }
            }
        }

        return [$depth, $count, $bytes];
    }

    private function markUnprocessable(Document $document, UnprocessableReason $reason, string $detail): void
    {
        $this->markUnprocessable->execute($document, $reason, $detail);
    }
}
