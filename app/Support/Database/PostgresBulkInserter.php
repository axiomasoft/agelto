<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Services\Llm\Schema\CanonicalJson;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use UnexpectedValueException;

/**
 * Пакетная вставка в PostgreSQL через UNNEST(...) с типизированным кодированием массивов
 * и очисткой NUL-байтов для UTF-8 text/jsonb.
 */
final readonly class PostgresBulkInserter
{
    /**
     * @param  array<string, 'int2'|'int4'|'int8'|'text'|'numeric'|'jsonb'>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return list<object>
     */
    public static function bulkInsert(string $table, array $columns, array $rows, ?string $returning = null): array
    {
        if ($rows === []) {
            return [];
        }

        $names = array_keys($columns);
        $bindings = $arrays = [];
        foreach ($columns as $name => $type) {
            $values = array_column($rows, $name);
            $bindings[] = match ($type) {
                'int2', 'int4', 'int8' => self::pgIntArray($values),
                'text' => self::pgTextArray($values),
                'numeric' => self::pgNumericArray($values),
                'jsonb' => self::pgJsonbArray($values),
            };
            $arrays[] = "?::{$type}[]";
        }
        $list = implode(', ', $names);
        $select = implode(', ', array_map(static fn (string $name): string => "v.{$name}", $names));
        $sql = "INSERT INTO {$table} ({$list}, created_at, updated_at) SELECT {$select}, clock_timestamp(), clock_timestamp()"
            .' FROM UNNEST('.implode(', ', $arrays).") AS v({$list})";
        if ($returning !== null) {
            $result = [];
            foreach (DB::select($sql." RETURNING {$returning}", $bindings) as $row) {
                if (! is_object($row)) {
                    throw new UnexpectedValueException('Expected object from INSERT RETURNING.');
                }
                $result[] = $row;
            }

            return $result;
        }

        DB::statement($sql, $bindings);

        return [];
    }

    /** @param list<mixed> $values */
    public static function pgIntArray(array $values): string
    {
        $encoded = [];
        foreach ($values as $value) {
            if ($value === null) {
                $encoded[] = 'NULL';

                continue;
            }
            if (! is_int($value)) {
                throw new InvalidArgumentException('Expected integer array value.');
            }
            $encoded[] = (string) $value;
        }

        return '{'.implode(',', $encoded).'}';
    }

    /** @param list<mixed> $values */
    public static function pgTextArray(array $values): string
    {
        $encoded = [];
        foreach ($values as $value) {
            if ($value === null) {
                $encoded[] = 'NULL';

                continue;
            }
            if (! is_string($value)) {
                throw new InvalidArgumentException('Expected text array value.');
            }
            $clean = str_replace("\0", '', $value);
            $encoded[] = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $clean).'"';
        }

        return '{'.implode(',', $encoded).'}';
    }

    /** @param list<mixed> $values */
    public static function pgNumericArray(array $values): string
    {
        $encoded = [];
        foreach ($values as $value) {
            if ($value === null) {
                $encoded[] = 'NULL';

                continue;
            }
            if (! is_string($value) || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value) !== 1) {
                throw new InvalidArgumentException('Expected decimal string array value.');
            }
            $encoded[] = $value;
        }

        return '{'.implode(',', $encoded).'}';
    }

    /** @param list<mixed> $values */
    public static function pgJsonbArray(array $values): string
    {
        foreach ($values as &$value) {
            if ($value === null) {
                continue;
            }
            if (! is_string($value) || ! json_validate($value)) {
                throw new InvalidArgumentException('Expected JSON array value.');
            }
            if (str_contains($value, '\\u0000')) {
                $value = CanonicalJson::encode(self::removeNullBytes(json_decode($value, flags: JSON_THROW_ON_ERROR)));
            }
        }
        unset($value);

        return self::pgTextArray($values);
    }

    public static function removeNullBytes(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace("\0", '', $value);
        }
        if (is_array($value)) {
            return array_map(self::removeNullBytes(...), $value);
        }
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = self::removeNullBytes($item);
            }
        }

        return $value;
    }
}
