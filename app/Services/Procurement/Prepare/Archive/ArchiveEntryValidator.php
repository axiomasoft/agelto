<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;

final class ArchiveEntryValidator
{
    /**
     * @param  list<ArchiveEntry>  $entries
     * @return list<ArchiveEntry>
     */
    public function validate(array $entries, ArchiveBudget $budget): array
    {
        $totalBytes = 0;
        $count = 0;
        $seen = [];
        $validated = [];

        foreach ($entries as $entry) {
            $path = ArchiveEntryPath::normalize($entry->path, $budget->maxPathLength);
            $key = mb_strtolower($path);

            if (isset($seen[$key])) {
                throw new ArchiveValidationException('коллидирующие пути записей архива');
            }
            $seen[$key] = true;

            if ($entry->isEncrypted) {
                throw new ArchivePasswordProtectedException('архив содержит зашифрованную запись');
            }
            if (! in_array($entry->type, ['file', 'directory'], true)) {
                throw new ArchiveValidationException('архив содержит special entry');
            }
            if ($entry->declaredSize < 0 || $entry->compressedSize < 0) {
                throw new ArchiveValidationException('архив содержит запись с невозможным размером');
            }
            if (++$count > $budget->maxEntries) {
                throw new ArchiveValidationException('превышен лимит числа записей архива');
            }
            if ($entry->declaredSize > $budget->maxEntryBytes) {
                throw new ArchiveValidationException('превышен лимит размера записи архива');
            }
            if ($entry->declaredSize > 0 && $entry->compressedSize === 0) {
                throw new ArchiveValidationException('невозможное соотношение размеров записи архива');
            }
            if ($entry->compressedSize > 0 && $entry->declaredSize / $entry->compressedSize > $budget->maxCompressionRatio) {
                throw new ArchiveValidationException('превышен коэффициент сжатия записи архива');
            }
            $totalBytes += $entry->declaredSize;
            if ($totalBytes > $budget->maxTotalBytes) {
                throw new ArchiveValidationException('превышен суммарный размер архива');
            }
            $validated[] = new ArchiveEntry(
                path: $path,
                declaredSize: $entry->declaredSize,
                compressedSize: $entry->compressedSize,
                isDirectory: $entry->isDirectory,
                isEncrypted: $entry->isEncrypted,
                type: $entry->type,
            );
        }

        return $validated;
    }
}
