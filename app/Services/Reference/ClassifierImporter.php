<?php

declare(strict_types=1);

namespace App\Services\Reference;

use Generator;
use RuntimeException;

/**
 * Разбор официальной выгрузки классификатора в построчные ассоц-массивы (без записи
 * в БД — это `LoadClassifiers`). Формат выгрузки не подтверждён (D16) — вынесен сюда,
 * за отдельный класс, чтобы сменить парсинг не трогая `ClassifierResolver`/команду.
 *
 * Текущий формат — CSV с заголовком `code,parent_code,level,name,valid_from,valid_to`
 * (+ `okpd2_code` для КТРУ), UTF-8.
 */
final readonly class ClassifierImporter
{
    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Не удалось открыть файл выгрузки: {$path}");
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false) {
                return;
            }

            while (($line = fgetcsv($handle)) !== false) {
                yield $this->normalize(row: array_combine($header, $line));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        return [
            'code' => $row['code'],
            'parent_code' => ($row['parent_code'] ?? '') !== '' ? $row['parent_code'] : null,
            'level' => (int) ($row['level'] ?? 0),
            'name' => $row['name'] ?? '',
            'okpd2_code' => ($row['okpd2_code'] ?? '') !== '' ? $row['okpd2_code'] : null,
            'valid_from' => ($row['valid_from'] ?? '') !== '' ? $row['valid_from'] : null,
            'valid_to' => ($row['valid_to'] ?? '') !== '' ? $row['valid_to'] : null,
            // Сырой JSON-текст из колонки выгрузки — передаётся в jsonb как есть,
            // без повторного json_encode на стороне LoadClassifiers.
            'attributes' => ($row['attributes'] ?? '') !== '' ? $row['attributes'] : null,
        ];
    }
}
