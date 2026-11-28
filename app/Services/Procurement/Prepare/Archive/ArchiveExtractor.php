<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use App\Services\Procurement\Prepare\Tools\BoundedToolProcess;

/** Adapter metadata/byte stream only: security policy lives in ArchiveEntryValidator. */
final readonly class ArchiveExtractor
{
    /** @var array<string, ArchiveFormatAdapter> */
    private array $adapters;

    public function __construct(
        BoundedToolProcess $tools,
        ?ZipArchiveAdapter $zip = null,
        ?SevenZipArchiveAdapter $sevenZip = null,
        ?RarArchiveAdapter $rar = null,
    ) {
        $this->adapters = [
            'zip' => $zip ?? new ZipArchiveAdapter,
            '7z' => $sevenZip ?? new SevenZipArchiveAdapter($tools),
            'rar' => $rar ?? new RarArchiveAdapter($tools),
        ];
    }

    /** @return list<ArchiveEntry> */
    public function listEntries(string $archivePath, string $format, ?float $deadlineAt = null): array
    {
        return $this->adapterFor($format)->listEntries($archivePath, $deadlineAt);
    }

    /**
     * Writes precisely one validated entry and verifies actual bytes against metadata.
     *
     * @param  int  $totalBytes  actual aggregate, maintained by caller for one archive
     */
    public function extractEntry(
        string $archivePath,
        string $format,
        ArchiveEntry $entry,
        string $targetPath,
        ArchiveBudget $budget,
        int &$totalBytes,
        ?float $deadlineAt = null,
    ): void {
        $this->adapterFor($format)->extractEntry($archivePath, $entry, $targetPath, $budget, $totalBytes, $deadlineAt);
    }

    private function adapterFor(string $format): ArchiveFormatAdapter
    {
        return $this->adapters[$format]
            ?? throw new ArchiveUnsupportedException("неизвестный формат архива: {$format}");
    }
}
