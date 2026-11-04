<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PartitionCoverage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Premake партиций на партиционированных таблицах (`config('partitioning.tables')`).
 * Основной путь — `pg_partman` (`partman.run_maintenance()`); если расширение
 * недоступно в окружении (managed Postgres без прав на `CREATE EXTENSION`) — сама
 * создаёт недостающие партиции сырым SQL, идемпотентно (`IF NOT EXISTS`).
 *
 * Осознанно `run_maintenance()` (SELECT-функция), а не `run_maintenance_proc()`
 * (CALL-процедура с внутренним COMMIT per-partition-set) — последняя не может
 * выполниться внутри уже открытой транзакции (в частности, внутри Feature-тестов на
 * `RefreshDatabase`); при 5 партиционированных таблицах на суточном расписании
 * "низкая contention" `run_maintenance_proc` не даёт выигрыша, который окупал бы это
 * ограничение.
 *
 * Retention: на пути pg_partman DROP старых партиций — встроенная часть
 * `run_maintenance()`, ничего не делаем сами — она читает `partman.part_config.retention`,
 * выставленный миграцией `2026_07_09_100050_set_pg_partman_retention.php` только для
 * `config('partitioning.retention')`-таблиц. Без pg_partman удаление устаревших партиций
 * выполняет `dropExpiredFallback()` по тому же конфигу.
 */
#[Description('Premake партиций на 3 месяца вперёд для партиционированных таблиц (pg_partman либо fallback)')]
#[Signature('partitions:maintain')]
final class MaintainPartitions extends Command
{
    public function handle(): int
    {
        if ($this->pgPartmanIsAvailable()) {
            DB::statement('SELECT partman.run_maintenance()');
            $this->assertContinuousCoverage();
            $this->info('Партиции обновлены через pg_partman (run_maintenance, вкл. retention-DROP по part_config).');

            return self::SUCCESS;
        }

        foreach ($this->partitionedTables() as $table) {
            $this->maintainFallback($table);
        }

        $this->assertContinuousCoverage();

        $this->info('pg_partman недоступен — партиции досозданы и просрочённые дропнуты fallback-командой.');

        return self::SUCCESS;
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
            // Retention may deliberately remove history; maintenance only promises the current
            // and premade future range. The forward migration repairs supported historical data.
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
