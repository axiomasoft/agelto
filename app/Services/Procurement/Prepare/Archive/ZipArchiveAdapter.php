<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use ZipArchive;

final readonly class ZipArchiveAdapter implements ArchiveFormatAdapter
{
    public function format(): string
    {
        return 'zip';
    }

    /** @return list<ArchiveEntry> */
    public function listEntries(string $archivePath, ?float $deadlineAt = null): array
    {
        $zip = $this->openArchive($archivePath);

        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                ArchiveAdapterSupport::assertWithinDeadline($deadlineAt);
                $stat = $zip->statIndex($index, ZipArchive::FL_ENC_RAW);
                if ($stat === false) {
                    throw new ArchiveUnsupportedException('ZIP metadata неполна');
                }

                $opsys = 0;
                $attributes = 0;
                $zip->getExternalAttributesIndex($index, $opsys, $attributes, ZipArchive::FL_ENC_RAW);
                if (! is_int($attributes)) {
                    throw new ArchiveUnsupportedException('ZIP attributes не содержат режим файла');
                }

                $rawName = ArchiveAdapterSupport::requiredString($stat['name'], 'ZIP name');
                $name = ArchiveAdapterSupport::decodeEntryName($rawName);
                $mode = ($attributes >> 16) & 0170000;
                $isDirectory = str_ends_with($name, '/') || str_ends_with($name, '\\') || $mode === 0040000;
                $type = match ($mode) {
                    0, 0100000, 0040000 => $isDirectory ? 'directory' : 'file',
                    0120000 => 'symlink',
                    default => 'special',
                };

                $entries[] = new ArchiveEntry(
                    path: $name,
                    declaredSize: ArchiveAdapterSupport::nonNegativeInt($stat['size'], 'ZIP size'),
                    compressedSize: ArchiveAdapterSupport::nonNegativeInt($stat['comp_size'], 'ZIP compressed size'),
                    isDirectory: $isDirectory,
                    isEncrypted: $stat['encryption_method'] !== ZipArchive::EM_NONE,
                    type: $type,
                );
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    public function extractEntry(
        string $archivePath,
        ArchiveEntry $entry,
        string $targetPath,
        ArchiveBudget $budget,
        int &$totalBytes,
        ?float $deadlineAt = null,
    ): void {
        $zip = $this->openArchive($archivePath);

        try {
            $index = $this->findEntryIndex($zip, $entry->path);
            $input = $index !== null
                ? $zip->getStreamIndex($index)
                : $zip->getStream($entry->path);

            if ($input === false) {
                throw new ArchiveUnsupportedException("не удалось открыть ZIP entry: {$entry->path}");
            }

            try {
                ArchiveAdapterSupport::copyBounded($input, $targetPath, $entry, $budget, $totalBytes, $deadlineAt);
            } finally {
                fclose($input);
            }
        } finally {
            $zip->close();
        }
    }

    private function openArchive(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new ArchiveUnsupportedException("не удалось открыть ZIP-архив: {$archivePath}");
        }

        return $zip;
    }

    private function findEntryIndex(ZipArchive $zip, string $entryPath): ?int
    {
        $normalizedTarget = rtrim(str_replace('\\', '/', $entryPath), '/');

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_ENC_RAW);
            if (! is_array($stat) || $stat['name'] === '') {
                continue;
            }

            $decoded = ArchiveAdapterSupport::decodeEntryName($stat['name']);
            if ($decoded === $entryPath || rtrim(str_replace('\\', '/', $decoded), '/') === $normalizedTarget) {
                return $index;
            }
        }

        return null;
    }
}
