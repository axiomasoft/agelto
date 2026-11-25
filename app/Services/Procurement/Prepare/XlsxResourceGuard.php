<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use ZipArchive;

/** Rejects unsafe XML in office ZIP containers before a document reader opens them. */
final class XlsxResourceGuard
{
    public function accepts(string $path, string $extension, bool $checkXmlEntities = true): bool
    {
        if ($extension !== 'xlsx') {
            return true;
        }

        $zip = new ZipArchive;

        if (@$zip->open($path, ZipArchive::CHECKCONS) !== true) {
            return false;
        }

        try {
            $maxEntries = $this->positiveInt('ai.prepare.xlsx_max_zip_entries', 10_000);
            $maxDecoded = $this->positiveInt('ai.prepare.xlsx_max_decoded_mb', 128) * 1024 * 1024;
            $maxRatio = $this->positiveInt('ai.prepare.xlsx_max_compression_ratio', 100);
            $maxCells = $this->positiveInt('ai.prepare.xlsx_max_cells', 2_000_000);

            if ($zip->numFiles > $maxEntries) {
                return false;
            }

            $decoded = 0;
            $compressed = 0;
            $cells = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    return false;
                }

                $decoded += $stat['size'];
                $compressed += $stat['comp_size'];

                if ($decoded > $maxDecoded) {
                    return false;
                }

                $name = $stat['name'];

                if ($checkXmlEntities && preg_match('/\.(?:xml|rels)$/i', $name) === 1 && $this->entryContainsProhibitedXml($zip, $name, $maxDecoded)) {
                    return false;
                }

                if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name) === 1) {
                    $reader = new \XMLReader;
                    $uri = 'zip://'.((string) realpath($path)).'#'.$name;

                    if (! $reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT)) {
                        return false;
                    }

                    try {
                        while ($reader->read()) {
                            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'c' && ++$cells > $maxCells) {
                                return false;
                            }
                        }
                    } finally {
                        $reader->close();
                    }
                }
            }

            return $decoded === 0 || ($compressed > 0 && $decoded / $compressed <= $maxRatio);
        } finally {
            $zip->close();
        }
    }

    public function assertSafeXmlEntries(string $filePath): void
    {
        $zip = new ZipArchive;

        if (@$zip->open($filePath, ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('Не удалось открыть ZIP-контейнер офисного документа.');
        }

        try {
            $maxDecoded = $this->positiveInt('ai.prepare.xlsx_max_decoded_mb', 128) * 1024 * 1024;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    throw new \RuntimeException('Не удалось прочитать метаданные ZIP-контейнера офисного документа.');
                }

                $name = $stat['name'];

                if (preg_match('/\.(?:xml|rels)$/i', $name) === 1
                    && ($stat['size'] > $maxDecoded || $this->entryContainsProhibitedXml($zip, $name, $maxDecoded))) {
                    throw new \RuntimeException("XML-запись {$name} недоступна, превышает лимит размера или содержит DOCTYPE/ENTITY.");
                }
            }
        } finally {
            $zip->close();
        }
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

                if (stripos($normalized, '<!doctype') !== false || stripos($normalized, '<!entity') !== false) {
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

    private function positiveInt(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value < 1) {
            throw new \UnexpectedValueException("{$key} must be a positive integer.");
        }

        return $value;
    }
}
