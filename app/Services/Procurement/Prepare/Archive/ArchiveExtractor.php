<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

/** Adapter metadata/byte stream only: security policy lives in ArchiveEntryValidator. */
final readonly class ArchiveExtractor
{
    /** @return list<ArchiveEntry> */
    public function listEntries(string $archivePath, string $format): array
    {
        return match ($format) {
            'zip' => $this->listZip($archivePath),
            '7z' => $this->listSevenZip($archivePath),
            'rar' => $this->listRar($archivePath),
            default => throw new ArchiveUnsupportedException("неизвестный формат архива: {$format}"),
        };
    }

    /**
     * Writes precisely one validated entry and verifies actual bytes against metadata.
     *
     * @param  int  $totalBytes  actual aggregate, maintained by caller for one archive
     */
    public function extractEntry(string $archivePath, string $format, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes): void
    {
        match ($format) {
            'zip' => $this->extractZipEntry($archivePath, $entry, $targetPath, $budget, $totalBytes),
            '7z' => $this->extractSevenZipEntry($archivePath, $entry, $targetPath, $budget, $totalBytes),
            'rar' => $this->extractRarEntry($archivePath, $entry, $targetPath, $budget, $totalBytes),
            default => throw new ArchiveUnsupportedException("неизвестный формат архива: {$format}"),
        };
    }

    /** @return list<ArchiveEntry> */
    private function listZip(string $archivePath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) {
            throw new ArchiveUnsupportedException("не удалось открыть ZIP-архив: {$archivePath}");
        }

        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new ArchiveUnsupportedException('ZIP metadata неполна');
                }
                $opsys = 0;
                $attributes = 0;
                $zip->getExternalAttributesIndex($index, $opsys, $attributes);
                $mode = ($attributes >> 16) & 0170000;
                $isDirectory = str_ends_with((string) $stat['name'], '/') || $mode === 0040000;
                $type = match ($mode) {
                    0, 0100000, 0040000 => $isDirectory ? 'directory' : 'file',
                    0120000 => 'symlink',
                    default => 'special',
                };
                $entries[] = new ArchiveEntry(
                    path: (string) $stat['name'],
                    declaredSize: (int) $stat['size'],
                    compressedSize: (int) $stat['comp_size'],
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

    private function extractZipEntry(string $archivePath, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) {
            throw new ArchiveUnsupportedException("не удалось открыть ZIP-архив: {$archivePath}");
        }

        $input = $zip->getStream($entry->path);
        if ($input === false) {
            $zip->close();
            throw new ArchiveUnsupportedException("не удалось открыть ZIP entry: {$entry->path}");
        }

        try {
            $this->copyBounded($input, $targetPath, $entry, $budget, $totalBytes);
        } finally {
            fclose($input);
            $zip->close();
        }
    }

    /** @return list<ArchiveEntry> */
    private function listSevenZip(string $archivePath): array
    {
        $result = Process::timeout($this->timeout())->run([(string) config('ai.tools.sevenzip_bin'), 'l', '-slt', $archivePath]);
        if ($result->failed()) {
            $this->rejectByOutput($result);
        }

        $afterSeparator = explode('----------', $result->output(), 2)[1] ?? '';
        $entries = [];
        foreach (preg_split('/\n{2,}/', trim($afterSeparator)) ?: [] as $block) {
            $parsed = $this->parseSevenZipEntryBlock($block);
            if ($parsed !== null) {
                $entries[] = $parsed;
            }
        }

        return $entries;
    }

    private function parseSevenZipEntryBlock(string $block): ?ArchiveEntry
    {
        $values = [];
        foreach (explode("\n", $block) as $line) {
            [$key, $value] = array_pad(explode(' = ', trim($line), 2), 2, null);
            if ($key !== null && $value !== null) {
                $values[$key] = $value;
            }
        }
        if (! isset($values['Path'])) {
            return null;
        }
        $attributes = (string) ($values['Attributes'] ?? '');
        $isDirectory = str_contains($attributes, 'D');
        $type = str_contains($attributes, 'L') || isset($values['Symbolic Link']) ? 'symlink' : ($isDirectory ? 'directory' : 'file');

        return new ArchiveEntry(
            path: (string) $values['Path'],
            declaredSize: (int) ($values['Size'] ?? 0),
            compressedSize: (int) ($values['Packed Size'] ?? 0),
            isDirectory: $isDirectory,
            isEncrypted: ($values['Encrypted'] ?? '') === '+',
            type: $type,
        );
    }

    private function extractSevenZipEntry(string $archivePath, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes): void
    {
        $this->extractProcessEntry(
            [(string) config('ai.tools.sevenzip_bin'), 'x', '-so', '-y', $archivePath, $entry->path],
            $entry,
            $targetPath,
            $budget,
            $totalBytes,
        );
    }

    private function extractRarEntry(string $archivePath, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes): void
    {
        $this->extractProcessEntry(
            [(string) config('ai.tools.unar_bin'), '-o', '-', '-D', '-q', $archivePath, $entry->path],
            $entry,
            $targetPath,
            $budget,
            $totalBytes,
        );
    }

    /** @param list<string> $command */
    private function extractProcessEntry(array $command, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes): void
    {
        $output = fopen($targetPath, 'xb');
        if ($output === false) {
            throw new RuntimeException("не удалось создать временный файл entry: {$targetPath}");
        }
        $bytes = 0;
        $hash = hash_init('sha256');
        try {
            $result = Process::timeout($this->timeout())->run(
                $command,
                function (string $type, string $buffer) use ($output, $entry, $budget, &$totalBytes, &$bytes, $hash): void {
                    if ($type !== 'out') {
                        return;
                    }
                    $bytes += strlen($buffer);
                    $totalBytes += strlen($buffer);
                    if ($bytes > $entry->declaredSize || $bytes > $budget->maxEntryBytes || $totalBytes > $budget->maxTotalBytes) {
                        throw new ArchiveValidationException('фактические байты записи архива превысили бюджет');
                    }
                    hash_update($hash, $buffer);
                    if (fwrite($output, $buffer) !== strlen($buffer)) {
                        throw new RuntimeException('не удалось записать временный файл entry');
                    }
                },
            );
            if ($result->failed()) {
                $this->rejectByOutput($result);
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
    }

    /** @return list<ArchiveEntry> */
    private function listRar(string $archivePath): array
    {
        $result = Process::timeout($this->timeout())->run(['lsar', '-json', $archivePath]);
        $decoded = json_decode($result->output(), true);
        if ($result->failed() || ! is_array($decoded) || ! is_array($decoded['lsarContents'] ?? null)) {
            $this->rejectByOutput($result);
        }

        return array_map(fn (array $entry): ArchiveEntry => new ArchiveEntry(
            path: (string) ($entry['XADFileName'] ?? ''),
            declaredSize: (int) ($entry['XADFileSize'] ?? 0),
            compressedSize: (int) ($entry['XADCompressedSize'] ?? 0),
            isDirectory: (bool) ($entry['XADIsDirectory'] ?? false),
            isEncrypted: (bool) ($entry['XADIsEncrypted'] ?? false),
            type: (bool) ($entry['XADIsLink'] ?? false) ? 'symlink' : ((bool) ($entry['XADIsDirectory'] ?? false) ? 'directory' : 'file'),
        ), $decoded['lsarContents']);
    }

    /** @param resource $input */
    private function copyBounded($input, string $targetPath, ArchiveEntry $entry, ArchiveBudget $budget, int &$totalBytes): void
    {
        $output = fopen($targetPath, 'xb');
        if ($output === false) {
            throw new RuntimeException("не удалось создать временный файл entry: {$targetPath}");
        }
        $bytes = 0;
        try {
            while (! feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false) {
                    throw new ArchiveUnsupportedException('ошибка чтения ZIP entry');
                }
                $bytes += strlen($chunk);
                $totalBytes += strlen($chunk);
                if ($bytes > $entry->declaredSize || $bytes > $budget->maxEntryBytes || $totalBytes > $budget->maxTotalBytes) {
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
    }

    private function rejectByOutput(ProcessResult $result): never
    {
        $output = trim($result->errorOutput().$result->output());
        if (str_contains(mb_strtolower($output), 'password')) {
            throw new ArchivePasswordProtectedException($output);
        }
        throw new ArchiveUnsupportedException($output !== '' ? $output : 'распаковщик не смог обработать архив');
    }

    private function timeout(): int
    {
        return (int) config('ai.prepare.archive.timeout_seconds', config('ai.tools.default_timeout_seconds', 180));
    }
}
