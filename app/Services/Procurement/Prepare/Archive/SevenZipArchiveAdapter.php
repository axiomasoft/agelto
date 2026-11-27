<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use App\Services\Procurement\Prepare\Tools\BoundedToolProcess;
use App\Support\Config\TypedConfig;

final class SevenZipArchiveAdapter implements ArchiveFormatAdapter
{
    /** @var array<string, array<string, string>> */
    private array $rawMemberPaths = [];

    public function __construct(
        private readonly BoundedToolProcess $tools,
    ) {}

    public function format(): string
    {
        return '7z';
    }

    /** @return list<ArchiveEntry> */
    public function listEntries(string $archivePath, ?float $deadlineAt = null): array
    {
        unset($this->rawMemberPaths[$archivePath]);
        $entries = [];

        ArchiveAdapterSupport::runTool(
            tools: $this->tools,
            command: [
                TypedConfig::nonEmptyString('ai.tools.sevenzip_bin'),
                'l',
                '-slt',
                '-y',
                '-p',
                '--',
            ],
            archivePath: $archivePath,
            deadlineAt: $deadlineAt,
            consumeOutput: function ($input) use ($archivePath, $deadlineAt, &$entries): void {
                $entries = $this->readListing($input, $archivePath, $deadlineAt);
            },
        );

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
            command: [TypedConfig::nonEmptyString('ai.tools.sevenzip_bin'), 'x', '-so', '-y', '-p', '-spd', '--'],
            archivePath: $archivePath,
            deadlineAt: $deadlineAt,
            member: $this->rawMemberPath($archivePath, $entry->path),
            consumeOutput: static function ($input) use ($targetPath, $entry, $budget, &$totalBytes, $deadlineAt): void {
                ArchiveAdapterSupport::copyBounded($input, $targetPath, $entry, $budget, $totalBytes, $deadlineAt);
            },
            maxOutputBytes: $limit,
        );
    }

    /**
     * @param  resource  $input
     * @return list<ArchiveEntry>
     */
    private function readListing($input, string $archivePath, ?float $deadlineAt): array
    {
        $entries = [];
        $fields = [];
        $readingEntries = false;

        while (($line = fgets($input)) !== false) {
            ArchiveAdapterSupport::assertWithinDeadline($deadlineAt);
            $line = rtrim($line, "\r\n");
            if (! $readingEntries) {
                if (preg_match('/^Encrypted\s*=\s*\+/', $line) === 1) {
                    throw new ArchivePasswordProtectedException('архив содержит зашифрованные заголовки');
                }
                $readingEntries = $line === '----------';

                continue;
            }
            if ($line === '') {
                $entry = $this->parseEntry($fields, $archivePath);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
                $fields = [];

                continue;
            }
            $parts = explode(' = ', $line, 2);
            if (isset($parts[1])) {
                if (array_key_exists($parts[0], $fields)) {
                    throw new ArchiveUnsupportedException('повторяющееся поле записи 7z');
                }
                $fields[$parts[0]] = $parts[1];
            }
        }

        if (! feof($input)) {
            throw new ArchiveUnsupportedException('ошибка чтения списка записей 7z');
        }
        $entry = $this->parseEntry($fields, $archivePath);
        if ($entry !== null) {
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @param array<string, string> $values */
    private function parseEntry(array $values, string $archivePath): ?ArchiveEntry
    {
        if (! isset($values['Path'])) {
            return null;
        }

        $rawPath = $values['Path'];
        $path = ArchiveAdapterSupport::decodeEntryName($rawPath);
        $this->rememberRawMemberPath($archivePath, $rawPath, $path);

        $attributes = $values['Attributes'] ?? '';
        $isDirectory = str_contains($attributes, 'D') || (($values['Folder'] ?? '') === '+') || str_ends_with($path, '/') || str_ends_with($path, '\\');
        $isSymlink = str_contains($attributes, 'L') || (($values['Symbolic Link'] ?? '') !== '');
        $isHardLink = isset($values['Hard Link']);
        $type = $isSymlink ? 'symlink' : ($isHardLink ? 'hardlink' : ($isDirectory ? 'directory' : 'file'));

        $size = $this->parseNumber($values['Size'] ?? '', 'Size') ?? 0;
        $packedSize = $this->parseNumber($values['Packed Size'] ?? '', 'Packed Size');
        $block = $this->parseNumber($values['Block'] ?? '', 'Block');
        if ($type === 'file' && $size > 0 && $packedSize === null && $block === null) {
            throw new ArchiveValidationException('неизвестен solid-блок записи 7z без Packed Size');
        }

        return new ArchiveEntry(
            path: $path,
            declaredSize: $size,
            compressedSize: $packedSize,
            isDirectory: $isDirectory,
            isEncrypted: ($values['Encrypted'] ?? '') === '+',
            type: $type,
            compressionBlock: $block,
        );
    }

    private function parseNumber(string $value, string $field): ?int
    {
        if ($value === '') {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (! ctype_digit($value) || ! is_int($number)) {
            throw new ArchiveValidationException("невалидный размер или номер блока 7z ({$field})");
        }

        return $number;
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
