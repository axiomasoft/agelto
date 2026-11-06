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
    public function listEntries(string $archivePath, string $format, ?float $deadlineAt = null): array
    {
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
        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) {
            throw new ArchiveUnsupportedException("не удалось открыть ZIP-архив: {$archivePath}");
        }

        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $this->assertWithinDeadline(deadlineAt: $deadlineAt);
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new ArchiveUnsupportedException('ZIP metadata неполна');
                }
                $opsys = 0;
                $attributes = 0;
                $zip->getExternalAttributesIndex($index, $opsys, $attributes);
                if (! is_int($attributes)) {
                    throw new ArchiveUnsupportedException('ZIP attributes не содержат режим файла');
                }
                $mode = ($attributes >> 16) & 0170000;
                $isDirectory = str_ends_with($this->requiredString($stat['name'], 'ZIP name'), '/') || $mode === 0040000;
                $type = match ($mode) {
                    0, 0100000, 0040000 => $isDirectory ? 'directory' : 'file',
                    0120000 => 'symlink',
                    default => 'special',
                };
                $entries[] = new ArchiveEntry(
                    path: $this->requiredString($stat['name'], 'ZIP name'),
                    declaredSize: $this->nonNegativeInt($stat['size'], 'ZIP size'),
                    compressedSize: $this->nonNegativeInt($stat['comp_size'], 'ZIP compressed size'),
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

    private function extractZipEntry(string $archivePath, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes, ?float $deadlineAt): void
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
            $this->copyBounded($input, $targetPath, $entry, $budget, $totalBytes, $deadlineAt);
        } finally {
            fclose($input);
            $zip->close();
        }
    }

    /** @return list<ArchiveEntry> */
    private function listSevenZip(string $archivePath, ?float $deadlineAt): array
    {
        $result = Process::timeout($this->timeout(deadlineAt: $deadlineAt))->run([
            $this->configString('ai.tools.sevenzip_bin'),
            'l',
            '-slt',
            '-y',
            '-p',
            '--',
            $archivePath,
        ]);
        if ($result->failed()) {
            $this->rejectByOutput($result);
        }

        [$headerSection, $afterSeparator] = array_pad(explode('----------', $result->output(), 2), 2, '');
        if (preg_match('/^Encrypted\s*=\s*\+/m', $headerSection) === 1) {
            throw new ArchivePasswordProtectedException('архив содержит зашифрованные заголовки');
        }

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
            // Solid 7z stores Packed Size on the block, not on every member.
            compressedSize: isset($values['Packed Size']) ? (int) $values['Packed Size'] : null,
            isDirectory: $isDirectory,
            isEncrypted: ($values['Encrypted'] ?? '') === '+',
            type: $type,
        );
    }

    private function extractSevenZipEntry(string $archivePath, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes, ?float $deadlineAt): void
    {
        $this->extractProcessEntry(
            [$this->configString('ai.tools.sevenzip_bin'), 'x', '-so', '-y', '-p', '-spd', '--', $archivePath, $entry->path],
            $entry,
            $targetPath,
            $budget,
            $totalBytes,
            $deadlineAt,
        );
    }

    private function extractRarEntry(string $archivePath, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes, ?float $deadlineAt): void
    {
        $this->extractProcessEntry(
            [$this->configString('ai.tools.unar_bin'), '-o', '-', '-D', '-q', $archivePath, $entry->path],
            $entry,
            $targetPath,
            $budget,
            $totalBytes,
            $deadlineAt,
        );
    }

    /** @param list<string> $command */
    private function extractProcessEntry(array $command, ArchiveEntry $entry, string $targetPath, ArchiveBudget $budget, int &$totalBytes, ?float $deadlineAt): void
    {
        $output = fopen($targetPath, 'xb');
        if ($output === false) {
            throw new RuntimeException("не удалось создать временный файл entry: {$targetPath}");
        }
        $bytes = 0;
        try {
            $result = Process::timeout($this->timeout(deadlineAt: $deadlineAt))->run(
                $command,
                function (string $type, string $buffer) use ($output, $entry, $budget, &$totalBytes, &$bytes): void {
                    if ($type !== 'out') {
                        return;
                    }
                    $bytes += strlen($buffer);
                    $totalBytes += strlen($buffer);
                    if ($bytes > $entry->declaredSize || $bytes > $budget->maxEntryBytes || $totalBytes > $budget->maxTotalBytes) {
                        throw new ArchiveValidationException('фактические байты записи архива превысили бюджет');
                    }
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
    private function listRar(string $archivePath, ?float $deadlineAt): array
    {
        $result = Process::timeout($this->timeout(deadlineAt: $deadlineAt))->run(['lsar', '-json', $archivePath]);
        $decoded = json_decode($result->output(), true);
        if ($result->failed() || ! is_array($decoded) || ! is_array($decoded['lsarContents'] ?? null)) {
            $this->rejectByOutput($result);
        }

        $entries = [];
        foreach ($decoded['lsarContents'] as $entry) {
            if (! is_array($entry)) {
                throw new ArchiveUnsupportedException('RAR metadata содержит не объект записи');
            }
            $isDirectory = $entry['XADIsDirectory'] ?? false;
            $isLink = $entry['XADIsLink'] ?? false;
            $isEncrypted = $entry['XADIsEncrypted'] ?? false;
            if (! is_bool($isDirectory) || ! is_bool($isLink) || ! is_bool($isEncrypted)) {
                throw new ArchiveUnsupportedException('RAR metadata содержит невалидные флаги записи');
            }
            $entries[] = new ArchiveEntry(
                path: $this->requiredString($entry['XADFileName'] ?? null, 'RAR filename'),
                declaredSize: $this->nonNegativeInt($entry['XADFileSize'] ?? null, 'RAR size'),
                compressedSize: $this->nonNegativeInt($entry['XADCompressedSize'] ?? null, 'RAR compressed size'),
                isDirectory: $isDirectory,
                isEncrypted: $isEncrypted,
                type: $isLink ? 'symlink' : ($isDirectory ? 'directory' : 'file'),
            );
        }

        return $entries;
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
