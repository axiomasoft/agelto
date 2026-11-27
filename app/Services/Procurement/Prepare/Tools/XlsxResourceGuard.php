<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Tools;

use App\Support\Config\TypedConfig;
use RuntimeException;
use XMLReader;
use ZipArchive;

/** Rejects unsafe XML in office ZIP containers before a document reader opens them. */
final class XlsxResourceGuard
{
    public function accepts(string $path, string $extension, bool $checkXmlEntities = true): bool
    {
        $realPath = $this->resolveSafeLocalPath($path);
        if ($realPath === null) {
            return false;
        }

        if ($extension !== 'xlsx') {
            return true;
        }

        $zip = new ZipArchive;
        if (@$zip->open($realPath, ZipArchive::CHECKCONS) !== true) {
            return false;
        }

        try {
            return $this->validateXlsxZip($zip, $realPath, $checkXmlEntities);
        } finally {
            $zip->close();
        }
    }

    public function assertSafeXmlEntries(string $filePath): void
    {
        $realPath = $this->resolveSafeLocalPath($filePath);
        if ($realPath === null) {
            throw new RuntimeException('Файл офисного документа недоступен или указан через потоковую обёртку.');
        }

        $zip = new ZipArchive;
        if (@$zip->open($realPath, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Не удалось открыть ZIP-контейнер офисного документа.');
        }

        try {
            $maxDecoded = TypedConfig::positiveInt('ai.prepare.xlsx_max_decoded_mb', 128) * 1024 * 1024;

            $this->iterateZipEntries($zip, function (array $stat) use ($zip, $maxDecoded): bool {
                $name = (string) $stat['name'];
                if ($this->isXmlOrRels($name)
                    && ((int) $stat['size'] > $maxDecoded || $this->entryContainsProhibitedXml($zip, $name, $maxDecoded))) {
                    throw new RuntimeException("XML-запись {$name} недоступна, превышает лимит размера или содержит DOCTYPE/ENTITY.");
                }

                return true;
            }, throwOnStatFailure: true);
        } finally {
            $zip->close();
        }
    }

    private function validateXlsxZip(ZipArchive $zip, string $realPath, bool $checkXmlEntities): bool
    {
        $maxEntries = TypedConfig::positiveInt('ai.prepare.xlsx_max_zip_entries', 10_000);
        if ($zip->numFiles > $maxEntries) {
            return false;
        }

        $maxDecoded = TypedConfig::positiveInt('ai.prepare.xlsx_max_decoded_mb', 128) * 1024 * 1024;
        $maxRatio = TypedConfig::positiveInt('ai.prepare.xlsx_max_compression_ratio', 100);
        $maxCells = TypedConfig::positiveInt('ai.prepare.xlsx_max_cells', 2_000_000);

        $decoded = 0;
        $compressed = 0;
        $cells = 0;

        $ok = $this->iterateZipEntries($zip, function (array $stat) use (
            $zip,
            $realPath,
            $checkXmlEntities,
            $maxDecoded,
            $maxCells,
            &$decoded,
            &$compressed,
            &$cells,
        ): bool {
            $decoded += (int) $stat['size'];
            $compressed += (int) $stat['comp_size'];

            if ($decoded > $maxDecoded) {
                return false;
            }

            $name = (string) $stat['name'];
            if ($checkXmlEntities && $this->isXmlOrRels($name) && $this->entryContainsProhibitedXml($zip, $name, $maxDecoded)) {
                return false;
            }

            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name) === 1
                && ! $this->countWorksheetCells($realPath, $name, $cells, $maxCells)) {
                return false;
            }

            return true;
        });

        return $ok && ($decoded === 0 || ($compressed > 0 && $decoded / $compressed <= $maxRatio));
    }

    /**
     * @param  callable(array<string, int|string>): bool  $visitor
     */
    private function iterateZipEntries(ZipArchive $zip, callable $visitor, bool $throwOnStatFailure = false): bool
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                if ($throwOnStatFailure) {
                    throw new RuntimeException('Не удалось прочитать метаданные ZIP-контейнера офисного документа.');
                }

                return false;
            }

            if (! $visitor($stat)) {
                return false;
            }
        }

        return true;
    }

    private function countWorksheetCells(string $realPath, string $name, int &$cells, int $maxCells): bool
    {
        $reader = new XMLReader;
        if (! $reader->open('zip://'.$realPath.'#'.$name, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return false;
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    return false;
                }

                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c' && ++$cells > $maxCells) {
                    return false;
                }
            }

            return true;
        } finally {
            $reader->close();
        }
    }

    private function isXmlOrRels(string $name): bool
    {
        return preg_match('/\.(?:xml|rels)$/i', $name) === 1;
    }

    private function entryContainsProhibitedXml(ZipArchive $zip, string $name, int $maxBytes): bool
    {
        $stream = @$zip->getStream($name);
        if (! is_resource($stream)) {
            return true;
        }

        try {
            $read = 0;
            $tail = '';
            $firstChunk = true;

            while (($remaining = $maxBytes - $read) > 0 && ! feof($stream)) {
                $chunk = @fread($stream, min(65536, $remaining));
                if ($chunk === false) {
                    return true;
                }
                if ($chunk === '') {
                    return ! feof($stream);
                }

                $read += strlen($chunk);
                $window = $tail.$chunk;
                $normalized = str_replace("\x00", '', $window);

                if ($firstChunk && $this->hasProhibitedXmlEncoding($chunk)) {
                    return true;
                }

                $firstChunk = false;

                if (str_contains($window, "\x1B") || stripos($normalized, '+ADw-') !== false
                    || stripos($normalized, '<!doctype') !== false || stripos($normalized, '<!entity') !== false) {
                    return true;
                }

                $tail = substr($window, -64);
            }

            // A full final chunk may leave EOF unset even when the entry fits exactly.
            if ($read >= $maxBytes && ! feof($stream)) {
                return @fread($stream, 1) !== '' || ! feof($stream);
            }

            return false;
        } finally {
            fclose($stream);
        }
    }

    private function resolveSafeLocalPath(string $path): ?string
    {
        if (str_contains($path, '://')) {
            return null;
        }

        $realPath = realpath($path);

        return $realPath !== false && is_file($realPath) ? $realPath : null;
    }

    private function hasProhibitedXmlEncoding(string $chunk): bool
    {
        $prologue = ltrim(str_replace("\x00", '', $chunk), "\xEF\xBB\xBF\xFF\xFE");

        if (! str_starts_with($prologue, '<?xml')) {
            return false;
        }

        if (preg_match('/\A<\?xml\b(.*?)\?>/si', $prologue, $declaration) !== 1) {
            return true;
        }

        if (preg_match('/\bencoding\s*=\s*(["\'])(.*?)\1/i', $declaration[1], $encoding) !== 1) {
            return false;
        }

        return ! in_array(strtoupper($encoding[2]), [
            'UTF-8', 'UTF-16', 'UTF-16LE', 'UTF-16BE', 'WINDOWS-1251', 'CP1251',
        ], true);
    }
}
