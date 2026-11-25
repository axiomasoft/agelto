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
    public function validate(array $entries, ArchiveBudget $budget, ?int $archiveSizeBytes = null): array
    {
        if ($archiveSizeBytes !== null && $archiveSizeBytes < 0) {
            throw new ArchiveValidationException('архив содержит запись с невозможным размером');
        }

        $totalBytes = 0;
        $knownCompressedBytes = 0;
        $hasUnknownCompressedSize = false;
        $count = 0;
        $seen = [];
        $validated = [];

        foreach ($entries as $entry) {
            $path = ArchiveEntryPath::normalize($entry->path, $budget->maxPathLength);
            $normalized = \Normalizer::normalize($path, \Normalizer::FORM_C);
            $nfc = is_string($normalized) ? $normalized : $path;
            $normalizedKey = mb_strtolower($nfc, 'UTF-8');

            if (isset($seen[$normalizedKey])) {
                throw new ArchiveValidationException('коллидирующие пути записей архива');
            }
            $seen[$normalizedKey] = true;

            if ($entry->isEncrypted) {
                throw new ArchivePasswordProtectedException('архив содержит зашифрованную запись');
            }
            if (! in_array($entry->type, ['file', 'directory'], true)) {
                throw new ArchiveValidationException('архив содержит special entry');
            }
            if ($entry->declaredSize < 0 || ($entry->compressedSize !== null && $entry->compressedSize < 0)) {
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
            $entryCompressedBytes = $entry->compressedSize ?? $archiveSizeBytes;
            if ($entry->declaredSize > 0 && $entry->compressedSize === null) {
                $hasUnknownCompressedSize = true;
                if ($archiveSizeBytes === null || $archiveSizeBytes === 0) {
                    throw new ArchiveValidationException('неизвестен физический размер архива для записи без Packed Size');
                }
            }
            if ($entryCompressedBytes !== null && $entryCompressedBytes > 0 && $entry->declaredSize / $entryCompressedBytes > $budget->maxCompressionRatio) {
                throw new ArchiveValidationException('превышен коэффициент сжатия записи архива');
            }
            $knownCompressedBytes += $entry->compressedSize ?? 0;
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

        if ($totalBytes > 0) {
            $denominator = $hasUnknownCompressedSize
                ? $archiveSizeBytes
                : min($knownCompressedBytes, $archiveSizeBytes ?? $knownCompressedBytes);
            if ($denominator === null || $denominator <= 0 || $totalBytes / $denominator > $budget->maxCompressionRatio) {
                throw new ArchiveValidationException('превышен суммарный коэффициент сжатия архива');
            }
        }

        return $validated;
    }
}
