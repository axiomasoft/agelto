<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Services\Procurement\Prepare\Gge\GgeXmlParser;
use RuntimeException;

final class NativeEstimateFormatDetector
{
    public function detect(string $filePath): ?string
    {
        if (str_contains($filePath, '://') || ($realPath = realpath($filePath)) === false || ! is_file($realPath)) {
            throw new RuntimeException('Native estimate must be a local file.');
        }

        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read native estimate.');
        }
        try {
            $prefix = fread($handle, 4096);
        } finally {
            fclose($handle);
        }
        if ($prefix === false) {
            throw new RuntimeException('Cannot read native estimate.');
        }

        $arps = '1#АРПС 1.10#';
        if (str_contains($prefix, $arps)
            || str_contains($prefix, mb_convert_encoding($arps, 'Windows-1251', 'UTF-8'))
            || str_contains($prefix, mb_convert_encoding($arps, 'CP866', 'UTF-8'))) {
            return 'arps_1_10';
        }

        if (str_starts_with($prefix, "\xFF\xFE")) {
            $prefix = mb_convert_encoding($prefix, 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($prefix, "\xFE\xFF")) {
            $prefix = mb_convert_encoding($prefix, 'UTF-8', 'UTF-16BE');
        }

        $withoutComments = preg_replace('/<!--.*?-->/s', '', $prefix);
        if ($withoutComments === null
            || preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:<\?xml\b[^>]*\?>\s*)?<(Construction|Document)\b[^>]*>/is', $withoutComments, $root) !== 1) {
            return null;
        }

        if ($root[1] === 'Construction'
            && preg_match('/\b(?:xsi:)?noNamespaceSchemaLocation\s*=\s*(["\'])([^"\']+)\1/is', $root[0], $schema) === 1) {
            $family = GgeXmlParser::schemaFamily($schema[2]);

            return $family === 'gge_unknown' ? null : $family;
        }

        if ($root[1] === 'Document'
            && (preg_match('/\bProgramVersion\s*=\s*(["\'])GRAND[^"\']*\1/i', $root[0]) === 1
                || preg_match('/<Chapters\b|<Chapter\b/i', $withoutComments) === 1)) {
            return 'grand_smeta_xml';
        }

        return null;
    }
}
