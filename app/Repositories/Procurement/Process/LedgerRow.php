<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use Illuminate\Support\Facades\DB;

/**
 * Типизированное чтение строки explicit SQL ledger: без Eloquent lazy-load под locks и без
 * молчаливой коэрции. Неожиданный тип колонки — LogicException, а не default.
 */
final readonly class LedgerRow
{
    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    /** @param  array<int, mixed>  $bindings */
    public static function one(string $sql, array $bindings = []): ?self
    {
        $row = DB::selectOne($sql, $bindings);

        return $row === null ? null : self::from($row);
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return list<self>
     */
    public static function all(string $sql, array $bindings = []): array
    {
        return array_values(array_map(self::from(...), DB::select($sql, $bindings)));
    }

    /** Placeholders для IN-списка: значения передаются bindings, не интерполируются. */
    public static function placeholders(int $count): string
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('IN list requires at least one value.');
        }

        return implode(', ', array_fill(0, $count, '?'));
    }

    public function string(string $column): string
    {
        $value = $this->values[$column] ?? null;

        return is_string($value) ? $value : throw new \LogicException('Expected text column: '.$column);
    }

    public function nullableString(string $column): ?string
    {
        return ($this->values[$column] ?? null) === null ? null : $this->string($column);
    }

    public function int(string $column): int
    {
        $value = $this->values[$column] ?? null;
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) ? $value : throw new \LogicException('Expected integer column: '.$column);
    }

    public function nullableInt(string $column): ?int
    {
        return ($this->values[$column] ?? null) === null ? null : $this->int($column);
    }

    public function bool(string $column): bool
    {
        $value = $this->values[$column] ?? null;

        return is_bool($value) ? $value : throw new \LogicException('Expected boolean column: '.$column);
    }

    private static function from(mixed $row): self
    {
        if (! is_object($row)) {
            throw new \LogicException('Database row must be an object.');
        }
        /** @var array<string, mixed> $values */
        $values = get_object_vars($row);

        return new self($values);
    }
}
