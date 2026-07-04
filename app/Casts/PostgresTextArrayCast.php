<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast для нативного Postgres `text[]` (например, `prc_procurements.okpd2_codes`).
 *
 * Eloquent/PDO не разбирают массивы автоматически — этот cast парсит/собирает
 * литерал вида `{a,b,c}` в/из `array<string>`.
 *
 * @implements CastsAttributes<array<int, string>|null, array<int, string>|null>
 */
final class PostgresTextArrayCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim(string: (string) $value, characters: '{}');

        if ($trimmed === '') {
            return [];
        }

        return $this->parseElements($trimmed);
    }

    /**
     * Разбирает содержимое postgres-литерала между `{}` на элементы, учитывая
     * `"..."`-обрамление и backslash-экранирование (не CSV-конвенция с двойными кавычками).
     *
     * @return array<int, string>
     */
    private function parseElements(string $body): array
    {
        $elements = [];
        $current = '';
        $inQuotes = false;
        $length = mb_strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($body, $i, 1);

            if ($char === '\\' && $i + 1 < $length) {
                $current .= mb_substr($body, $i + 1, 1);
                $i++;

                continue;
            }

            if ($char === '"') {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if ($char === ',' && ! $inQuotes) {
                $elements[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $elements[] = $current;

        return $elements;
    }

    /**
     * @param  array<int, string>|null  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $escaped = array_map(
            static fn (string $item): string => '"'.str_replace('"', '\\"', $item).'"',
            $value,
        );

        return '{'.implode(',', $escaped).'}';
    }
}
