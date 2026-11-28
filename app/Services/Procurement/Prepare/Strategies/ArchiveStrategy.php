<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Strategies;

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
use App\Services\Procurement\Prepare\Archive\ArchiveWorkspaceHelper;
use App\Services\Procurement\Prepare\Tools\PreparedRepresentationHelper;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\PipelineTemporaryFile;
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
        [$depth, $ancestorEntries, $ancestorBytes] = ArchiveWorkspaceHelper::lineageUsage($version);
        if ($depth >= $budget->maxDepth) {
            PreparedRepresentationHelper::markUnprocessable($this->markUnprocessable, $document, UnprocessableReason::ZipBomb, 'превышена допустимая глубина вложенности архивов');

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
                ArchiveWorkspaceHelper::copyToLocal(Storage::disk($location->disk), $location->path, $localArchive, $budget, $deadlineAt);
                $archiveSize = @filesize($localArchive);
                $entries = $this->entryValidator->validate(
                    entries: $this->extractor->listEntries(archivePath: $localArchive, format: $version->extension, deadlineAt: $deadlineAt),
                    budget: $budget,
                    archiveSizeBytes: $archiveSize === false ? null : $archiveSize,
                );
                $this->extractChildren($document, $version, $localArchive, $entries, $budget, $deadlineAt);
            } catch (ArchivePasswordProtectedException $exception) {
                PreparedRepresentationHelper::markUnprocessable($this->markUnprocessable, $document, UnprocessableReason::PasswordProtected, $exception->getMessage());

                return PreparationResult::Unprocessable;
            } catch (ArchiveUnsupportedException $exception) {
                PreparedRepresentationHelper::markUnprocessable($this->markUnprocessable, $document, UnprocessableReason::ArchiveUnsupported, $exception->getMessage());

                return PreparationResult::Unprocessable;
            } catch (ArchiveValidationException $exception) {
                PreparedRepresentationHelper::markUnprocessable($this->markUnprocessable, $document, UnprocessableReason::ZipBomb, $exception->getMessage());

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
}
