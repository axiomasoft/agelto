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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function process(Document $document, DocumentVersion $version): PreparationResult
    {
        $budget = ArchiveBudget::fromConfig();
        if ($this->nestingDepth($version) >= $budget->maxDepth) {
            $this->markUnprocessable($document, UnprocessableReason::ZipBomb, 'превышена допустимая глубина вложенности архивов');

            return PreparationResult::Unprocessable;
        }

        $location = $this->locationResolver->forDocumentVersion($version);
        $localArchive = tempnam(sys_get_temp_dir(), 'archive_');
        if ($localArchive === false) {
            throw new \RuntimeException('не удалось создать временный файл архива');
        }

        try {
            file_put_contents($localArchive, Storage::disk($location->disk)->get($location->path));

            try {
                $entries = $this->entryValidator->validate($this->extractor->listEntries($localArchive, $version->extension), $budget);
                $this->extractChildren($document, $version, $localArchive, $entries, $budget);
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
    private function extractChildren(Document $document, DocumentVersion $version, string $localArchive, array $entries, ArchiveBudget $budget): void
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
                if ($entry->isDirectory) {
                    continue;
                }

                $this->expandArchiveEntry->execute(
                    parent: $document,
                    parentVersion: $version,
                    entryPath: $entry->path,
                    localPath: $extractPath.'/'.$entry->path,
                );
            }
        } finally {
            File::deleteDirectory($extractPath);
        }
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
