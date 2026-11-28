<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process\Support;

use App\Support\Database\SqlRow;

/**
 * Типизированное чтение строки explicit SQL ledger: без Eloquent lazy-load под locks и без
 * молчаливой коэрции. Неожиданный тип колонки — LogicException, а не default.
 */
final readonly class LedgerRow
{
    private function __construct(private SqlRow $row) {}

    /** @param  array<int, mixed>  $bindings */
    public static function one(string $sql, array $bindings = []): ?self
    {
        $row = SqlRow::one($sql, $bindings);

        return $row === null ? null : new self($row);
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return list<self>
     */
    public static function all(string $sql, array $bindings = []): array
    {
        return array_map(static fn (SqlRow $row): self => new self($row), SqlRow::all($sql, $bindings));
    }

    /** Placeholders для IN-списка: значения передаются bindings, не интерполируются. */
    public static function placeholders(int $count): string
    {
        return SqlRow::placeholders($count);
    }

    public function string(string $column): string
    {
        return $this->row->string($column);
    }

    public function nullableString(string $column): ?string
    {
        return $this->row->nullableString($column);
    }

    public function int(string $column): int
    {
        return $this->row->int($column);
    }

    public function nullableInt(string $column): ?int
    {
        return $this->row->nullableInt($column);
    }

    public function bool(string $column): bool
    {
        return $this->row->bool($column);
    }
}
