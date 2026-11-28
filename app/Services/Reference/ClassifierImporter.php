<?php

declare(strict_types=1);

namespace App\Services\Reference;

use DateTimeImmutable;
use Generator;
use RuntimeException;

/**
 * Разбор официальной выгрузки классификатора в построчные ассоц-массивы (без записи
 * в БД — это `LoadClassifiers`). Формат выгрузки не подтверждён — вынесен сюда,
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
            $header = fgetcsv($handle, escape: '');

            if ($header === false) {
                return;
            }

            $columns = [];

            foreach ($header as $column) {
                if (is_string($column) && $columns === [] && str_starts_with($column, "\xEF\xBB\xBF")) {
                    $column = substr($column, 3);
                }

                if (! is_string($column) || $column === '') {
                    throw new RuntimeException("Выгрузка содержит пустой или нестроковый заголовок: {$path}");
                }
                if (! mb_check_encoding($column, 'UTF-8')) {
                    throw new RuntimeException("Заголовок выгрузки содержит некорректный UTF-8: {$path}");
                }

                $columns[] = $column;
            }

            if (count($columns) !== count(array_unique($columns))) {
                throw new RuntimeException("Выгрузка содержит повторяющиеся заголовки: {$path}");
            }

            if (! in_array('code', $columns, true)) {
                throw new RuntimeException("В заголовке выгрузки отсутствует обязательная колонка code: {$path}");
            }

            while (($line = fgetcsv($handle, escape: '')) !== false) {
                // fgetcsv отдаёт пустую строку как [null]; строка из пустых ячеек — не данные.
                if ($this->isBlankLine($line)) {
                    continue;
                }

                $values = [];

                foreach ($line as $value) {
                    if (! is_string($value)) {
                        throw new RuntimeException("Выгрузка содержит нестроковое значение: {$path}");
                    }
                    if (! mb_check_encoding($value, 'UTF-8')) {
                        throw new RuntimeException("Строка выгрузки содержит некорректный UTF-8: {$path}");
                    }

                    $values[] = $value;
                }

                if (count($columns) !== count($values)) {
                    throw new RuntimeException("Строка выгрузки не соответствует заголовку: {$path}");
                }

                $row = array_combine($columns, $values);

                yield $this->normalize(row: $row);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string|null>  $line
     */
    private function isBlankLine(array $line): bool
    {
        foreach ($line as $cell) {
            if ($cell !== null && $cell !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $code = $row['code'];
        if (trim($code) === '') {
            throw new RuntimeException('В строке выгрузки отсутствует код классификатора.');
        }

        $level = $row['level'] ?? '0';
        if (! preg_match('/^\d+$/D', $level) || (int) $level > 2_147_483_647) {
            throw new RuntimeException("Некорректный level для кода «{$code}».");
        }

        $attributes = ($row['attributes'] ?? '') !== '' ? $row['attributes'] : null;
        if ($attributes !== null && ! json_validate($attributes)) {
            throw new RuntimeException("Некорректный JSON в колонке attributes для кода «{$row['code']}».");
        }

        return [
            'code' => $code,
            'parent_code' => ($row['parent_code'] ?? '') !== '' ? $row['parent_code'] : null,
            'level' => (int) $level,
            'name' => $row['name'] ?? '',
            'okpd2_code' => ($row['okpd2_code'] ?? '') !== '' ? $row['okpd2_code'] : null,
            'valid_from' => $this->date(value: $row['valid_from'] ?? '', column: 'valid_from', code: $code),
            'valid_to' => $this->date(value: $row['valid_to'] ?? '', column: 'valid_to', code: $code),
            // Сырой JSON-текст из колонки выгрузки — передаётся в jsonb как есть,
            // без повторного json_encode на стороне LoadClassifiers.
            'attributes' => $attributes,
        ];
    }

    private function date(string $value, string $column, string $code): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = str_contains($value, "\0") ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value || $date->format('Y') === '0000') {
            throw new RuntimeException("Некорректная дата {$column} для кода «{$code}».");
        }

        return $value;
    }
}
