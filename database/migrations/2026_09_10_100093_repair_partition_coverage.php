<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const int HISTORY_MONTHS = 12;

    private const string COMMENT = 'agelto:p0.1:partition-coverage-repair';

    /** `invalid_object_definition`: диапазон уже покрыт другой партицией. */
    private const string OVERLAPPING_PARTITION_SQLSTATE = '42P17';

    public function up(): void
    {
        foreach ($this->partitionedTables() as $table) {
            foreach ($this->months() as $month) {
                $this->createPartition($table, $month);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->partitionedTables() as $table) {
            $partitions = DB::select(
                <<<'SQL'
                    SELECT child.relname AS name
                    FROM pg_inherits inheritance
                    JOIN pg_class child ON child.oid = inheritance.inhrelid
                    JOIN pg_class parent ON parent.oid = inheritance.inhparent
                    WHERE parent.relname = ?
                      AND obj_description(child.oid, 'pg_class') = ?
                    SQL,
                [$table, self::COMMENT],
            );

            foreach ($partitions as $partition) {
                $name = $partition->name ?? null;

                if (! is_string($name) || ! $this->isIdentifier($name)) {
                    continue;
                }

                DB::statement("DROP TABLE IF EXISTS {$name}");
            }
        }
    }

    private function createPartition(string $table, Carbon $month): void
    {
        $partition = $table.'_'.$month->format('Y_m');

        if ((bool) DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS exists', [$partition])->exists) {
            return;
        }

        $from = $month->toDateString();
        $to = $month->copy()->addMonthNoOverflow()->toDateString();

        // Наличие проверяется по ДИАПАЗОНУ, а не по имени: на свежей БД pg_partman (миграция
        // 100047) уже создал месяц `now()+3` под своим именем `{$table}_pYYYYMMDD`, и CREATE с
        // именем `{$table}_YYYY_MM` падал с 42P17 (overlap) — `migrate` чистой установки
        // останавливался на этой миграции. Попытка изолирована savepoint'ом, поэтому overlap
        // откатывает только её, а не всю транзакцию миграции.
        try {
            DB::transaction(static function () use ($table, $partition, $from, $to): void {
                DB::statement(
                    "CREATE TABLE IF NOT EXISTS {$partition} PARTITION OF {$table} "
                    ."FOR VALUES FROM ('{$from}') TO ('{$to}')",
                );
                DB::statement("COMMENT ON TABLE {$partition} IS '".self::COMMENT."'");
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== self::OVERLAPPING_PARTITION_SQLSTATE) {
                throw $exception;
            }
        }
    }

    /** @return list<Carbon> */
    private function months(): array
    {
        $start = Carbon::now('UTC')->startOfMonth()->subMonthsNoOverflow(self::HISTORY_MONTHS);
        $premake = config('partitioning.premake');

        if (! is_int($premake) || $premake < 0) {
            throw new RuntimeException('partitioning.premake должен быть неотрицательным целым числом.');
        }

        return array_map(
            static fn (int $month): Carbon => $start->copy()->addMonthsNoOverflow($month),
            range(0, self::HISTORY_MONTHS + $premake),
        );
    }

    /** @return list<string> */
    private function partitionedTables(): array
    {
        $tables = config('partitioning.tables');

        if (! is_array($tables)) {
            throw new RuntimeException('partitioning.tables должен быть массивом.');
        }

        $names = array_keys($tables);

        foreach ($names as $table) {
            if (! is_string($table) || ! $this->isIdentifier($table)) {
                throw new RuntimeException('partitioning.tables содержит невалидное имя таблицы.');
            }
        }

        return $names;
    }

    private function isIdentifier(string $identifier): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $identifier) === 1;
    }
};
