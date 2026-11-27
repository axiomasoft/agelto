<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;

interface ArchiveFormatAdapter
{
    public function format(): string;

    /** @return list<ArchiveEntry> */
    public function listEntries(string $archivePath, ?float $deadlineAt = null): array;

    /**
     * Writes precisely one validated entry and verifies actual bytes against metadata.
     *
     * @param  int  $totalBytes  actual aggregate, maintained by caller for one archive
     */
    public function extractEntry(
        string $archivePath,
        ArchiveEntry $entry,
        string $targetPath,
        ArchiveBudget $budget,
        int &$totalBytes,
        ?float $deadlineAt = null,
    ): void;
}
