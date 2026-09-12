<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use ZipArchive;

/** Rejects hostile OOXML containers before PhpSpreadsheet inflates metadata or cell data. */
final class XlsxResourceGuard
{
    public function accepts(string $path, string $extension): bool
    {
        if ($extension !== 'xlsx') {
            return true;
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
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

    private function positiveInt(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value < 1) {
            throw new \UnexpectedValueException("{$key} must be a positive integer.");
        }

        return $value;
    }
}
