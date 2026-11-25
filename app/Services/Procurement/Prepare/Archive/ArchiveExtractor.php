<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Exceptions\Procurement\Prepare\ToolUnavailable;
use App\Services\Procurement\Prepare\BoundedToolProcess;
use Closure;
use RuntimeException;
use ZipArchive;

/** Adapter metadata/byte stream only: security policy lives in ArchiveEntryValidator. */
final class ArchiveExtractor
{
    private ?ZipArchive $openZip = null;

    private ?string $openZipKey = null;

    /** @var array<string, int> */
    private array $openZipIndices = [];

    /** @var array<string, array<string, string>> */
    private array $rawMemberPaths = [];

    public function __construct(private readonly BoundedToolProcess $tools) {}

    public function __destruct()
    {
        $this->closeZip();
    }

    /** @return list<ArchiveEntry> */
    public function listEntries(string $archivePath, string $format, ?float $deadlineAt = null): array
    {
        $this->closeZip();
        unset($this->rawMemberPaths[$archivePath]);

        return match ($format) {
            'zip' => $this->listZip(archivePath: $archivePath, deadlineAt: $deadlineAt),
            '7z' => $this->listSevenZip(archivePath: $archivePath, deadlineAt: $deadlineAt),
            'rar' => $this->listRar(archivePath: $archivePath, deadlineAt: $deadlineAt),
            default => throw new ArchiveUnsupportedException("неизвестный формат архива: {$format}"),
        };
    }

    /**
     * Writes precisely one validated entry and verifies actual bytes against metadata.
     *
     * @param  int  $totalBytes  actual aggregate, maintained by caller for one archive
     */
    public function extractEntry(string $archivePath, string $format, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes, ?float $deadlineAt = null): void
    {
        match ($format) {
            'zip' => $this->extractZipEntry($archivePath, $entry, $targetPath, $budget, $totalBytes, $deadlineAt),
            '7z' => $this->extractSevenZipEntry($archivePath, $entry, $targetPath, $budget, $totalBytes, $deadlineAt),
            'rar' => $this->extractRarEntry($archivePath, $entry, $targetPath, $budget, $totalBytes, $deadlineAt),
            default => throw new ArchiveUnsupportedException("неизвестный формат архива: {$format}"),
        };
    }

    /** @return list<ArchiveEntry> */
    private function listZip(string $archivePath, ?float $deadlineAt): array
    {
        $zip = $this->openZipArchive($archivePath);

        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $this->assertWithinDeadline(deadlineAt: $deadlineAt);
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
                $rawName = $this->requiredString($stat['name'], 'ZIP name');
                $name = $this->decodeEntryName($rawName);
                $mode = ($attributes >> 16) & 0170000;
                $isDirectory = str_ends_with($name, '/') || str_ends_with($name, '\\') || $mode === 0040000;
                $type = match ($mode) {
                    0, 0100000, 0040000 => $isDirectory ? 'directory' : 'file',
                    0120000 => 'symlink',
                    default => 'special',
                };
                $this->rememberZipIndex($name, $index);
                $entries[] = new ArchiveEntry(
                    path: $name,
                    declaredSize: $this->nonNegativeInt($stat['size'], 'ZIP size'),
                    compressedSize: $this->nonNegativeInt($stat['comp_size'], 'ZIP compressed size'),
                    isDirectory: $isDirectory,
                    isEncrypted: $stat['encryption_method'] !== ZipArchive::EM_NONE,
                    type: $type,
                );
            }

            return $entries;
        } catch (\Throwable $exception) {
            $this->closeZip();

            throw $exception;
        }
    }

    private function extractZipEntry(string $archivePath, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes, ?float $deadlineAt): void
    {
        $zip = $this->zipFor($archivePath);
        $index = $this->openZipIndices[$entry->path]
            ?? $this->openZipIndices[rtrim(str_replace('\\', '/', $entry->path), '/')]
            ?? null;
        $input = $index !== null
            ? $zip->getStreamIndex($index)
            : $zip->getStream($entry->path);
        if ($input === false) {
            $this->closeZip();
            throw new ArchiveUnsupportedException("не удалось открыть ZIP entry: {$entry->path}");
        }

        try {
            $this->copyBounded($input, $targetPath, $entry, $budget, $totalBytes, $deadlineAt);
        } catch (\Throwable $exception) {
            $this->closeZip();

            throw $exception;
        } finally {
            fclose($input);
        }
    }

    /** @return list<ArchiveEntry> */
    private function listSevenZip(string $archivePath, ?float $deadlineAt): array
    {
        $entries = [];
        $this->runTool(
            command: [
                $this->configString('ai.tools.sevenzip_bin'),
                'l',
                '-slt',
                '-y',
                '-p',
                '--',
            ],
            archivePath: $archivePath,
            deadlineAt: $deadlineAt,
            consumeOutput: function ($input) use ($archivePath, $deadlineAt, &$entries): void {
                $entries = $this->readSevenZipListing($input, $archivePath, $deadlineAt);
            },
        );

        return $entries;
    }

    /**
     * @param  resource  $input
     * @return list<ArchiveEntry>
     */
    private function readSevenZipListing($input, string $archivePath, ?float $deadlineAt): array
    {
        $entries = [];
        $fields = [];
        $readingEntries = false;
        while (($line = fgets($input)) !== false) {
            $this->assertWithinDeadline(deadlineAt: $deadlineAt);
            $line = rtrim($line, "\r\n");
            if (! $readingEntries) {
                if (preg_match('/^Encrypted\s*=\s*\+/', $line) === 1) {
                    throw new ArchivePasswordProtectedException('архив содержит зашифрованные заголовки');
                }
                $readingEntries = $line === '----------';

                continue;
            }
            if ($line === '') {
                $entry = $this->parseSevenZipEntry($fields, $archivePath);
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
        $entry = $this->parseSevenZipEntry($fields, $archivePath);
        if ($entry !== null) {
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @param array<string, string> $values */
    private function parseSevenZipEntry(array $values, string $archivePath): ?ArchiveEntry
    {
        if (! isset($values['Path'])) {
            return null;
        }
        $rawPath = $values['Path'];
        $path = $this->decodeEntryName($rawPath);
        $this->rememberRawMemberPath($archivePath, $rawPath, $path);
        $attributes = $values['Attributes'] ?? '';
        $isDirectory = str_contains($attributes, 'D') || (($values['Folder'] ?? '') === '+') || str_ends_with($path, '/') || str_ends_with($path, '\\');
        $isSymlink = str_contains($attributes, 'L') || (($values['Symbolic Link'] ?? '') !== '');
        $isHardLink = isset($values['Hard Link']);
        $type = $isSymlink ? 'symlink' : ($isHardLink ? 'hardlink' : ($isDirectory ? 'directory' : 'file'));
        $size = $this->sevenZipNumber($values['Size'] ?? '', 'Size') ?? 0;
        $packedSize = $this->sevenZipNumber($values['Packed Size'] ?? '', 'Packed Size');
        $block = $this->sevenZipNumber($values['Block'] ?? '', 'Block');
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

    private function sevenZipNumber(string $value, string $field): ?int
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

    private function extractSevenZipEntry(string $archivePath, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes, ?float $deadlineAt): void
    {
        $this->extractProcessEntry(
            command: [$this->configString('ai.tools.sevenzip_bin'), 'x', '-so', '-y', '-p', '-spd', '--'],
            archivePath: $archivePath,
            entry: $entry,
            targetPath: $targetPath,
            budget: $budget,
            totalBytes: $totalBytes,
            deadlineAt: $deadlineAt,
        );
    }

    private function extractRarEntry(string $archivePath, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes, ?float $deadlineAt): void
    {
        $this->extractProcessEntry(
            command: [$this->configString('ai.tools.unar_bin'), '-o', '-', '-D', '-q', '--'],
            archivePath: $archivePath,
            entry: $entry,
            targetPath: $targetPath,
            budget: $budget,
            totalBytes: $totalBytes,
            deadlineAt: $deadlineAt,
        );
    }

    /** @param non-empty-list<string> $command */
    private function extractProcessEntry(array $command, string $archivePath, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes, ?float $deadlineAt): void
    {
        $limit = min($entry->declaredSize, $budget->maxEntryBytes, $budget->maxTotalBytes - $totalBytes);
        if ($limit < 0) {
            throw new ArchiveValidationException('фактические байты записи архива превысили бюджет');
        }
        $this->runTool(
            command: $command,
            archivePath: $archivePath,
            deadlineAt: $deadlineAt,
            member: $this->rawMemberPath($archivePath, $entry->path),
            consumeOutput: function ($input) use ($targetPath, $entry, $budget, &$totalBytes, $deadlineAt): void {
                $this->copyBounded($input, $targetPath, $entry, $budget, $totalBytes, $deadlineAt);
            },
            maxOutputBytes: $limit,
        );
    }

    /** @return list<ArchiveEntry> */
    private function listRar(string $archivePath, ?float $deadlineAt): array
    {
        $output = $this->runTool(
            command: [$this->configString('ai.tools.lsar_bin'), '-json', '--'],
            archivePath: $archivePath,
            deadlineAt: $deadlineAt,
        );
        $decoded = json_decode($output, true);
        if (! is_array($decoded) || ! is_array($decoded['lsarContents'] ?? null)) {
            $this->rejectByOutput($output);
        }

        $entries = [];
        foreach ($decoded['lsarContents'] as $entry) {
            if (! is_array($entry)) {
                throw new ArchiveUnsupportedException('RAR metadata содержит не объект записи');
            }
            $rawPath = $this->requiredString($entry['XADFileName'] ?? null, 'RAR filename');
            $path = $this->decodeEntryName($rawPath);
            $this->rememberRawMemberPath($archivePath, $rawPath, $path);
            $isDirectory = $this->rarBool($entry['XADIsDirectory'] ?? false) || str_ends_with($path, '/') || str_ends_with($path, '\\');
            $isLink = $this->rarBool($entry['XADIsLink'] ?? false) || $this->rarBool($entry['XADIsHardLink'] ?? false);
            $isEncrypted = $this->rarBool($entry['XADIsEncrypted'] ?? false);
            $entries[] = new ArchiveEntry(
                path: $path,
                declaredSize: $this->nonNegativeInt($entry['XADFileSize'] ?? ($isDirectory ? 0 : null), 'RAR size'),
                compressedSize: $this->nonNegativeInt($entry['XADCompressedSize'] ?? ($isDirectory ? 0 : null), 'RAR compressed size'),
                isDirectory: $isDirectory,
                isEncrypted: $isEncrypted,
                type: $isLink ? 'symlink' : ($isDirectory ? 'directory' : 'file'),
            );
        }

        return $entries;
    }

    private function openZipArchive(string $archivePath): ZipArchive
    {
        $this->closeZip();
        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new ArchiveUnsupportedException("не удалось открыть ZIP-архив: {$archivePath}");
        }
        $this->openZip = $zip;
        $this->openZipKey = $this->zipCacheKey($archivePath);
        $this->openZipIndices = [];

        return $zip;
    }

    private function zipFor(string $archivePath): ZipArchive
    {
        if ($this->openZip !== null && $this->openZipKey === $this->zipCacheKey($archivePath)) {
            return $this->openZip;
        }

        $zip = $this->openZipArchive($archivePath);
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_ENC_RAW);
            if (is_array($stat) && $stat['name'] !== '') {
                $this->rememberZipIndex($this->decodeEntryName($stat['name']), $index);
            }
        }

        return $zip;
    }

    private function rememberZipIndex(string $decodedName, int $index): void
    {
        $this->openZipIndices[$decodedName] = $index;
        $normalized = rtrim(str_replace('\\', '/', $decodedName), '/');
        if ($normalized !== '') {
            $this->openZipIndices[$normalized] = $index;
        }
    }

    private function zipCacheKey(string $archivePath): string
    {
        clearstatcache(true, $archivePath);
        $stat = @stat($archivePath);
        if ($stat === false) {
            return $archivePath;
        }

        return sprintf('%s:%d:%d:%d', $archivePath, $stat['ino'], $stat['size'], $stat['mtime']);
    }

    private function closeZip(): void
    {
        if ($this->openZip !== null) {
            try {
                $this->openZip->close();
            } catch (\Throwable) {
            }
        }
        $this->openZip = null;
        $this->openZipKey = null;
        $this->openZipIndices = [];
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

    private function decodeEntryName(string $rawName): string
    {
        if (mb_check_encoding($rawName, 'UTF-8')) {
            return $rawName;
        }

        $isWindows1251 = preg_match('/[\xB0-\xDF\xF2-\xFB\xFD-\xFF]/', $rawName) === 1
            && preg_match('/[\x80-\xA7\xA9-\xAF]/', $rawName) !== 1;
        $encodings = $isWindows1251 ? ['Windows-1251', 'CP866'] : ['CP866', 'Windows-1251'];

        foreach ($encodings as $encoding) {
            $converted = @iconv($encoding, 'UTF-8', $rawName);
            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        throw new ArchiveUnsupportedException('не удалось декодировать имя записи архива');
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

    /** @param resource $input */
    private function copyBounded($input, string $targetPath, ArchiveEntry $entry, ArchiveBudget $budget, int &$totalBytes, ?float $deadlineAt): void
    {
        $output = fopen($targetPath, 'xb');
        if ($output === false) {
            throw new RuntimeException("не удалось создать временный файл entry: {$targetPath}");
        }
        $bytes = 0;
        try {
            while (! feof($input)) {
                $this->assertWithinDeadline(deadlineAt: $deadlineAt);
                $chunk = fread($input, 65536);
                if ($chunk === false || ($chunk === '' && ! feof($input))) {
                    throw new ArchiveUnsupportedException('ошибка чтения записи архива');
                }
                $bytes += strlen($chunk);
                if ($bytes > $entry->declaredSize || $bytes > $budget->maxEntryBytes || $totalBytes + $bytes > $budget->maxTotalBytes) {
                    throw new ArchiveValidationException('фактические байты записи архива превысили бюджет');
                }
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('не удалось записать временный файл entry');
                }
            }
            if ($bytes !== $entry->declaredSize) {
                throw new ArchiveValidationException('фактический размер записи не совпал с metadata');
            }
        } catch (\Throwable $exception) {
            fclose($output);
            unlink($targetPath);
            throw $exception;
        }
        fclose($output);
        $totalBytes += $bytes;
    }

    private function rejectByOutput(string $output): never
    {
        $output = trim($output);
        $lower = mb_strtolower($output, 'UTF-8');
        if (
            str_contains($lower, 'password')
            || str_contains($lower, 'can not open encrypted archive')
            || str_contains($lower, 'cannot open encrypted archive')
            || preg_match('/^encrypted\s*=\s*\+/mi', $output) === 1
        ) {
            throw new ArchivePasswordProtectedException($output !== '' ? $output : 'архив защищён паролем');
        }
        throw new ArchiveUnsupportedException($output !== '' ? $output : 'распаковщик не смог обработать архив');
    }

    private function timeout(?float $deadlineAt): int
    {
        $configured = $this->nonNegativeInt(config('ai.prepare.archive.timeout_seconds', config('ai.tools.default_timeout_seconds', 180)), 'archive timeout');

        if ($deadlineAt === null) {
            return $configured;
        }

        $remaining = (int) floor($deadlineAt - microtime(true) - $this->persistenceReserveSeconds());

        if ($remaining < 1) {
            throw new ArchiveValidationException('единый deadline распаковки исчерпан');
        }

        return min($configured, $remaining);
    }

    private function assertWithinDeadline(?float $deadlineAt): void
    {
        if ($deadlineAt !== null && microtime(true) >= $deadlineAt - $this->persistenceReserveSeconds()) {
            throw new ArchiveValidationException('единый deadline распаковки исчерпан');
        }
    }

    private function persistenceReserveSeconds(): int
    {
        return $this->nonNegativeInt(config('documents.format_tools.persistence_seconds', 120), 'archive persistence reserve');
    }

    /**
     * @param  non-empty-list<string>  $command
     * @param  (Closure(resource): void)|null  $consumeOutput
     */
    private function runTool(
        array $command,
        string $archivePath,
        ?float $deadlineAt,
        ?string $member = null,
        ?Closure $consumeOutput = null,
        int $maxOutputBytes = 8388608,
    ): string {
        $deadline = hrtime(true) / 1e9 + $this->timeout(deadlineAt: $deadlineAt);
        try {
            $source = $this->tools->inputPath($archivePath);
            $command[] = $source;
            if ($member !== null) {
                $command[] = $member;
            }

            return $this->tools->inWorkspace(
                prefix: 'archive_tool_',
                operation: fn (string $directory): string => $this->tools->run(
                    command: $command,
                    directory: $directory,
                    execution: null,
                    deadline: $deadline,
                    tmpBytes: $maxOutputBytes + 1048576,
                    inputPath: $source,
                    consumeOutput: $consumeOutput,
                    maxOutputBytes: $maxOutputBytes,
                    reportStderr: true,
                ),
            );
        } catch (ToolFailed|ToolUnavailable $exception) {
            if ($exception instanceof ToolFailed) {
                if ($exception->reason === 'page_timeout') {
                    throw new RuntimeException('Истекло время работы распаковщика.', previous: $exception);
                }
                if (in_array($exception->reason, ['tool_output_limit', 'tmp_limit'], true)) {
                    throw new ArchiveValidationException('вывод распаковщика превысил бюджет', previous: $exception);
                }
            }
            $this->rejectByOutput($exception->getMessage());
        }
    }

    private function configString(string $key): string
    {
        return $this->requiredString(config($key), $key);
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new ArchiveUnsupportedException("{$field} должен быть непустой строкой");
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 0) {
            throw new ArchiveUnsupportedException("{$field} должен быть неотрицательным целым числом");
        }

        return $value;
    }
}
