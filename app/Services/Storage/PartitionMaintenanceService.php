<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\PartitionCoverage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class PartitionMaintenanceService
{
    public const string ADVISORY_LOCK_KEY = 'agelto:partitions:maintain';

    public function acquireAdvisoryLock(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return true;
        }

        /** @var object{acquired?: bool}|null $row */
        $row = DB::selectOne('SELECT pg_try_advisory_xact_lock(hashtext(?)) AS acquired', [self::ADVISORY_LOCK_KEY]);

        return (bool) ($row->acquired ?? false);
    }

    /**
     * @return bool true when maintained via pg_partman, false when maintained via SQL fallback
     */
    public function maintain(): bool
    {
        if ($this->pgPartmanIsAvailable()) {
            DB::statement('SELECT partman.run_maintenance()');
            $this->assertContinuousCoverage();

            return true;
        }

        foreach ($this->partitionedTables() as $table) {
            $this->maintainFallback($table);
        }

        $this->assertContinuousCoverage();

        return false;
    }

    private function pgPartmanIsAvailable(): bool
    {
        return (bool) DB::selectOne("SELECT 1 AS installed FROM pg_extension WHERE extname = 'pg_partman'");
    }

    private function maintainFallback(string $table): void
    {
        $premake = $this->nonNegativeInt(config('partitioning.premake'), 'partitioning.premake');
        $start = Carbon::now('UTC')->startOfMonth();

        app(PartitionCoverage::class)->ensure(table: $table, from: $start, to: $start->copy()->addMonthsNoOverflow($premake + 1));

        $this->dropExpiredFallback($table);
    }

    /**
     * Fallback-эквивалент pg_partman retention: дропает партиции `$table`, чей верхний
     * рубеж диапазона старше `config('partitioning.retention')[$table]['months']`.
     * `keep_table=true` (зарезервировано) — партиции не дропаем; таблиц вне
     * `partitioning.retention` (в т.ч. `prc_revisions`) эта функция не касается.
     */
    private function dropExpiredFallback(string $table): void
    {
        $rule = config("partitioning.retention.{$table}");

        if ($rule === null) {
            return;
        }

        if (! is_array($rule) || ! is_bool($rule['keep_table'] ?? null)) {
            throw new \RuntimeException("partitioning.retention.{$table} содержит невалидное правило.");
        }

        if ($rule['keep_table']) {
            return;
        }

        $cutoff = Carbon::now('UTC')->startOfMonth()->subMonths($this->nonNegativeInt($rule['months'] ?? null, "partitioning.retention.{$table}.months"));

        $partitions = DB::select(
            'SELECT c.relname AS name, pg_get_expr(c.relpartbound, c.oid) AS bounds FROM pg_inherits i
                JOIN pg_class c ON c.oid = i.inhrelid
                JOIN pg_class p ON p.oid = i.inhparent
                WHERE p.relname = ?',
            [$table],
        );

        foreach ($partitions as $partition) {
            $attributes = is_object($partition) ? get_object_vars($partition) : [];
            $name = $attributes['name'] ?? null;

            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                continue;
            }

            $bounds = $attributes['bounds'] ?? null;
            if (! is_string($bounds) || preg_match("/TO \('([^']+)'\)/", $bounds, $groups) !== 1) {
                continue;
            }
            $upper = Carbon::parse($groups[1])->utc();
            if ($upper->lte($cutoff)) {
                DB::statement('DROP TABLE '.$name);
            }
        }
    }

    private function assertContinuousCoverage(): void
    {
        foreach ($this->partitionedTables() as $table) {
            app(PartitionCoverage::class)->ensure(
                table: $table,
                from: now('UTC')->startOfMonth(),
                to: now('UTC')->startOfMonth()->addMonthsNoOverflow($this->nonNegativeInt(config('partitioning.premake'), 'partitioning.premake') + 1),
            );
        }
    }

    /** @return list<string> */
    private function partitionedTables(): array
    {
        $tables = config('partitioning.tables');

        if (! is_array($tables)) {
            throw new \RuntimeException('partitioning.tables должен быть массивом.');
        }

        $result = [];

        foreach (array_keys($tables) as $table) {
            if (! is_string($table) || ! preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
                throw new \RuntimeException('partitioning.tables содержит невалидное имя таблицы.');
            }

            $result[] = $table;
        }

        return $result;
    }

    private function nonNegativeInt(mixed $value, string $key): int
    {
        if (! is_int($value) || $value < 0) {
            throw new \RuntimeException("{$key} должен быть неотрицательным целым числом.");
        }

        return $value;
    }
}
