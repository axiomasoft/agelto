<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Archive;

use Illuminate\Support\Facades\Process;
use ZipArchive;

/**
 * Диспетчер распаковки по формату (P3.6, D24) — три независимых пути: ZIP через `ZipArchive`
 * (канон, перенесён из `ArchiveStrategy` как есть — код покрыт тестами и правкой не рискует),
 * `7z` через `7z`/`7zz` (листинг `-slt`, извлечение по списку entry), `rar` через `unar`/`lsar`
 * (`unar` — единственный legal распаковщик, `unrar` из non-free не подключается, D24).
 *
 * Guard'ы бомбы и фильтр безопасных имён (`isSafeEntryName`) — забота вызывающей
 * `ArchiveStrategy`: этот класс только листит (даёт размер/имя КАЖДОГО entry ДО распаковки) и
 * распаковывает УЖЕ отфильтрованный список.
 */
final readonly class ArchiveExtractor
{
    /**
     * @return list<array{name: string, size: int}>
     *
     * @throws ArchivePasswordProtectedException
     * @throws ArchiveUnsupportedException
     */
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
     * @param  list<string>  $entries  относительные пути entry, разрешённые к распаковке
     */
    public function extract(string $archivePath, string $format, string $outputDir, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        match ($format) {
            'zip' => $this->extractZip($archivePath, $outputDir, $entries),
            '7z' => $this->extractSevenZip($archivePath, $outputDir, $entries),
            'rar' => $this->extractRar($archivePath, $outputDir, $entries),
            default => throw new ArchiveUnsupportedException("неизвестный формат архива: {$format}"),
        };
    }

    /**
     * @return list<array{name: string, size: int}>
     */
    private function listZip(string $archivePath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw new ArchiveUnsupportedException("не удалось открыть ZIP-архив: {$archivePath}");
        }

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $entries[] = ['name' => (string) $stat['name'], 'size' => (int) $stat['size']];
        }

        $zip->close();

        return $entries;
    }

    /**
     * @param  list<string>  $entries
     */
    private function extractZip(string $archivePath, string $outputDir, array $entries): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw new ArchiveUnsupportedException("не удалось открыть ZIP-архив: {$archivePath}");
        }

        $zip->extractTo($outputDir, $entries);
        $zip->close();
    }

    /**
     * @return list<array{name: string, size: int}>
     */
    private function listSevenZip(string $archivePath): array
    {
        $result = Process::timeout($this->timeout())
            ->run([(string) config('ai.tools.sevenzip_bin'), 'l', '-slt', $archivePath]);

        if ($result->failed()) {
            $this->rejectByOutput($result->errorOutput().$result->output());
        }

        $afterSeparator = explode('----------', $result->output(), 2)[1] ?? '';
        $blocks = preg_split('/\n{2,}/', trim($afterSeparator)) ?: [];

        $entries = [];
        $encrypted = false;

        foreach ($blocks as $block) {
            $parsed = $this->parseSevenZipEntryBlock($block);

            if ($parsed === null) {
                continue;
            }

            if ($parsed['encrypted']) {
                $encrypted = true;
            }

            if (! $parsed['isDirectory']) {
                $entries[] = ['name' => $parsed['name'], 'size' => $parsed['size']];
            }
        }

        if ($encrypted) {
            throw new ArchivePasswordProtectedException("архив зашифрован: {$archivePath}");
        }

        return $entries;
    }

    /**
     * @return array{name: string, size: int, isDirectory: bool, encrypted: bool}|null
     */
    private function parseSevenZipEntryBlock(string $block): ?array
    {
        $name = null;
        $size = 0;
        $isDirectory = false;
        $encrypted = false;

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'Path = ')) {
                $name = substr($line, strlen('Path = '));
            } elseif (str_starts_with($line, 'Size = ')) {
                $size = (int) substr($line, strlen('Size = '));
            } elseif (str_starts_with($line, 'Attributes = D')) {
                $isDirectory = true;
            } elseif ($line === 'Encrypted = +') {
                $encrypted = true;
            }
        }

        return $name === null ? null : compact('name', 'size', 'isDirectory', 'encrypted');
    }

    /**
     * @param  list<string>  $entries
     */
    private function extractSevenZip(string $archivePath, string $outputDir, array $entries): void
    {
        $result = Process::timeout($this->timeout())
            ->run([
                (string) config('ai.tools.sevenzip_bin'), 'x', '-y', '-o'.$outputDir, $archivePath, ...$entries,
            ]);

        if ($result->failed()) {
            $this->rejectByOutput($result->errorOutput().$result->output());
        }
    }

    /**
     * @return list<array{name: string, size: int}>
     */
    private function listRar(string $archivePath): array
    {
        $result = Process::timeout($this->timeout())
            ->run(['lsar', '-json', $archivePath]);

        $decoded = json_decode($result->output(), true);

        if ($result->failed() || ! is_array($decoded) || ! isset($decoded['lsarContents']) || ! is_array($decoded['lsarContents'])) {
            $this->rejectByOutput($result->errorOutput().$result->output());
        }

        $entries = [];

        foreach ($decoded['lsarContents'] as $entry) {
            if (($entry['XADIsEncrypted'] ?? false) === true) {
                throw new ArchivePasswordProtectedException("архив зашифрован: {$archivePath}");
            }

            if (! isset($entry['XADFileName'])) {
                continue;
            }

            $entries[] = ['name' => (string) $entry['XADFileName'], 'size' => (int) ($entry['XADFileSize'] ?? 0)];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $entries
     */
    private function extractRar(string $archivePath, string $outputDir, array $entries): void
    {
        $result = Process::timeout($this->timeout())
            ->run([(string) config('ai.tools.unar_bin'), '-o', $outputDir, '-f', $archivePath, ...$entries]);

        if ($result->failed()) {
            $this->rejectByOutput($result->errorOutput().$result->output());
        }
    }

    private function rejectByOutput(string $output): never
    {
        if (str_contains(mb_strtolower($output), 'password')) {
            throw new ArchivePasswordProtectedException(trim($output));
        }

        throw new ArchiveUnsupportedException(trim($output) !== '' ? trim($output) : 'распаковщик не смог обработать архив');
    }

    private function timeout(): int
    {
        return (int) config('ai.tools.default_timeout_seconds', 180);
    }
}
