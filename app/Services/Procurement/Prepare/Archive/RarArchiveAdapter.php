<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use App\Services\Procurement\Prepare\Tools\BoundedToolProcess;
use App\Support\Config\TypedConfig;

final class RarArchiveAdapter implements ArchiveFormatAdapter
{
    /** @var array<string, array<string, string>> */
    private array $rawMemberPaths = [];

    public function __construct(
        private readonly BoundedToolProcess $tools,
    ) {}

    public function format(): string
    {
        return 'rar';
    }

    /** @return list<ArchiveEntry> */
    public function listEntries(string $archivePath, ?float $deadlineAt = null): array
    {
        unset($this->rawMemberPaths[$archivePath]);

        $output = ArchiveAdapterSupport::runTool(
            tools: $this->tools,
            command: [TypedConfig::nonEmptyString('ai.tools.lsar_bin'), '-json', '--'],
            archivePath: $archivePath,
            deadlineAt: $deadlineAt,
        );
        $decoded = json_decode($output, true);
        if (! is_array($decoded) || ! is_array($decoded['lsarContents'] ?? null)) {
            ArchiveAdapterSupport::rejectByOutput($output);
        }

        $entries = [];
        foreach ($decoded['lsarContents'] as $entry) {
            if (! is_array($entry)) {
                throw new ArchiveUnsupportedException('RAR metadata содержит не объект записи');
            }

            $rawPath = ArchiveAdapterSupport::requiredString($entry['XADFileName'] ?? null, 'RAR filename');
            $path = ArchiveAdapterSupport::decodeEntryName($rawPath);
            $this->rememberRawMemberPath($archivePath, $rawPath, $path);

            $isDirectory = $this->rarBool($entry['XADIsDirectory'] ?? false) || str_ends_with($path, '/') || str_ends_with($path, '\\');
            $isLink = $this->rarBool($entry['XADIsLink'] ?? false) || $this->rarBool($entry['XADIsHardLink'] ?? false);
            $isEncrypted = $this->rarBool($entry['XADIsEncrypted'] ?? false);

            $entries[] = new ArchiveEntry(
                path: $path,
                declaredSize: ArchiveAdapterSupport::nonNegativeInt($entry['XADFileSize'] ?? ($isDirectory ? 0 : null), 'RAR size'),
                compressedSize: ArchiveAdapterSupport::nonNegativeInt($entry['XADCompressedSize'] ?? ($isDirectory ? 0 : null), 'RAR compressed size'),
                isDirectory: $isDirectory,
                isEncrypted: $isEncrypted,
                type: $isLink ? 'symlink' : ($isDirectory ? 'directory' : 'file'),
            );
        }

        return $entries;
    }

    public function extractEntry(
        string $archivePath,
        ArchiveEntry $entry,
        string $targetPath,
        ArchiveBudget $budget,
        int &$totalBytes,
        ?float $deadlineAt = null,
    ): void {
        $limit = min($entry->declaredSize, $budget->maxEntryBytes, $budget->maxTotalBytes - $totalBytes);
        if ($limit < 0) {
            throw new ArchiveValidationException('фактические байты записи архива превысили бюджет');
        }

        ArchiveAdapterSupport::runTool(
            tools: $this->tools,
            command: [TypedConfig::nonEmptyString('ai.tools.unar_bin'), '-o', '-', '-D', '-q', '--'],
            archivePath: $archivePath,
            deadlineAt: $deadlineAt,
            member: $this->rawMemberPath($archivePath, $entry->path),
            consumeOutput: static function ($input) use ($targetPath, $entry, $budget, &$totalBytes, $deadlineAt): void {
                ArchiveAdapterSupport::copyBounded($input, $targetPath, $entry, $budget, $totalBytes, $deadlineAt);
            },
            maxOutputBytes: $limit,
        );
    }

    private function rarBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        throw new ArchiveUnsupportedException('RAR metadata содержит невалидные флаги записи');
    }

    private function rememberRawMemberPath(string $archivePath, string $rawPath, string $decodedPath): void
    {
        $this->rawMemberPaths[$archivePath][$rawPath] = $rawPath;
        $this->rawMemberPaths[$archivePath][$decodedPath] = $rawPath;
        $normalized = rtrim(str_replace('\\', '/', $decodedPath), '/');
        if ($normalized !== '') {
            $this->rawMemberPaths[$archivePath][$normalized] = $rawPath;
        }
    }

    private function rawMemberPath(string $archivePath, string $entryPath): string
    {
        return $this->rawMemberPaths[$archivePath][$entryPath]
            ?? $this->rawMemberPaths[$archivePath][rtrim(str_replace('\\', '/', $entryPath), '/')]
            ?? $entryPath;
    }
}
