<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Arps;

use RuntimeException;

/**
 * @phpstan-type ArpsItem array{
 *     record_type: string, code: ?string, unit: ?string, name: ?string,
 *     direct_cost: ?string, workers_salary: ?string, machines_cost: ?string,
 *     machinist_salary: ?string, materials_cost: ?string, labor_workers: ?string,
 *     labor_machinists: ?string, quantity: ?string, section_num: mixed, section_name: mixed,
 *     coefficients: list<array<string, mixed>>, resources: list<array<string, mixed>>, fields: list<string>
 * }
 */
final class Arps110Parser
{
    private const int MAX_LINE_BYTES = 65536;

    /** @return array{passport: array<string, mixed>, sections: list<array<string, mixed>>, items: list<array<string, mixed>>} */
    public function parse(string $filePath): array
    {
        if (str_contains($filePath, '://') || ($realPath = realpath($filePath)) === false || ! is_file($realPath)) {
            throw new RuntimeException('ARPS input must be a local file.');
        }

        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open ARPS input.');
        }

        /** @var array<string, mixed> $passport */
        $passport = [];
        /** @var list<array<string, mixed>> $sections */
        $sections = [];
        /** @var list<ArpsItem> $items */
        $items = [];
        $encoding = null;
        $currentSection = null;
        /** @var ArpsItem|null $currentItem */
        $currentItem = null;

        try {
            while (($rawLine = fgets($handle, self::MAX_LINE_BYTES + 1)) !== false) {
                if (strlen($rawLine) === self::MAX_LINE_BYTES && ! str_ends_with($rawLine, "\n") && ! feof($handle)) {
                    throw new RuntimeException('ARPS line exceeds the size limit.');
                }
                if (trim($rawLine) === '') {
                    continue;
                }
                if ($encoding === null) {
                    $encoding = $this->detectEncoding($rawLine);
                }
                $line = $this->decode($rawLine, $encoding);
                $fields = explode('#', rtrim($line, "\r\n"));
                $type = $fields[0];

                if ($type === '0' || $type === '1') {
                    continue;
                }
                if ($type === '3') {
                    $passport = ['fields' => $fields];

                    continue;
                }
                if ($type === '10') {
                    if ($currentItem !== null) {
                        $items[] = $currentItem;
                        $currentItem = null;
                    }
                    $currentSection = [
                        'num' => $fields[1] ?? null,
                        'name' => $fields[2] ?? null,
                        'fields' => $fields,
                    ];
                    $sections[] = $currentSection;

                    continue;
                }
                if ($type === '20') {
                    $position = $this->position($fields, $currentSection);
                    if ($currentItem !== null && $this->isMaterialChild($fields)) {
                        $currentItem['resources'][] = $position;

                        continue;
                    }
                    if ($currentItem !== null) {
                        $items[] = $currentItem;
                    }
                    $currentItem = $position;

                    continue;
                }
                if ($type === '25' || $type === '30') {
                    if ($currentItem === null) {
                        throw new RuntimeException('ARPS subordinate record has no current position.');
                    }
                    $key = $type === '25' ? 'coefficients' : 'resources';
                    $currentItem[$key][] = [
                        'record_type' => $type,
                        'code' => $fields[2] ?? null,
                        'unit' => $fields[3] ?? null,
                        'name' => $fields[4] ?? null,
                        'fields' => $fields,
                    ];

                    continue;
                }

                throw new RuntimeException('Unsupported ARPS record type.');
            }
            if (! feof($handle)) {
                throw new RuntimeException('Cannot finish reading ARPS input.');
            }
            if ($encoding === null) {
                throw new RuntimeException('ARPS signature is missing.');
            }
            if ($currentItem !== null) {
                $items[] = $currentItem;
            }
        } finally {
            fclose($handle);
        }

        return ['passport' => $passport, 'sections' => $sections, 'items' => $items];
    }

    private function detectEncoding(string $firstLine): string
    {
        $utf8 = ltrim($firstLine, "\xEF\xBB\xBF");
        $signature = '1#АРПС 1.10#';
        if (str_starts_with($utf8, $signature) && mb_check_encoding($utf8, 'UTF-8')) {
            return 'UTF-8';
        }
        foreach (['Windows-1251', 'CP866'] as $encoding) {
            if (str_starts_with($firstLine, mb_convert_encoding($signature, $encoding, 'UTF-8'))) {
                return $encoding;
            }
        }

        throw new RuntimeException('ARPS 1.10 signature is missing.');
    }

    private function decode(string $rawLine, string $encoding): string
    {
        if ($encoding === 'UTF-8') {
            if (! mb_check_encoding($rawLine, 'UTF-8')) {
                throw new RuntimeException('Invalid ARPS UTF-8 line.');
            }

            return ltrim($rawLine, "\xEF\xBB\xBF");
        }

        $decoded = mb_convert_encoding($rawLine, 'UTF-8', $encoding);
        if ($decoded === false) {
            throw new RuntimeException('Cannot decode ARPS line.');
        }

        return $decoded;
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>|null  $section
     * @return ArpsItem
     */
    private function position(array $fields, ?array $section): array
    {
        return [
            'record_type' => '20',
            'code' => $fields[2] ?? null,
            'unit' => $fields[3] ?? null,
            'name' => $fields[4] ?? null,
            'direct_cost' => $fields[15] ?? null,
            'workers_salary' => $fields[16] ?? null,
            'machines_cost' => $fields[17] ?? null,
            'machinist_salary' => $fields[18] ?? null,
            'materials_cost' => $fields[19] ?? null,
            'labor_workers' => $fields[23] ?? null,
            'labor_machinists' => $fields[24] ?? null,
            'quantity' => $fields[26] ?? null,
            'section_num' => $section['num'] ?? null,
            'section_name' => $section['name'] ?? null,
            'coefficients' => [],
            'resources' => [],
            'fields' => $fields,
        ];
    }

    /** @param list<string> $fields */
    private function isMaterialChild(array $fields): bool
    {
        return $this->emptyOrZero($fields[17] ?? '')
            && $this->emptyOrZero($fields[23] ?? '')
            && $this->emptyOrZero($fields[24] ?? '')
            && ! $this->emptyOrZero($fields[19] ?? '');
    }

    private function emptyOrZero(string $value): bool
    {
        $value = trim($value);

        return $value === '' || preg_match('/^[+-]?0+(?:[.,]0+)?$/D', $value) === 1;
    }
}
