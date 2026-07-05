<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
 */
final class MaintainPartitions extends Command
{
    protected $signature = 'partitions:maintain';

    protected $description = 'Premake партиций на 3 месяца вперёд для партиционированных таблиц (pg_partman либо fallback)';

    public function handle(): int
    {
        if ($this->pgPartmanIsAvailable()) {
            DB::statement('SELECT partman.run_maintenance()');
            $this->info('Партиции обновлены через pg_partman (run_maintenance).');

            return self::SUCCESS;
        }

        foreach (array_keys(config('partitioning.tables')) as $table) {
            $this->maintainFallback($table);
        }

        $this->info('pg_partman недоступен — партиции досозданы fallback-командой.');

        return self::SUCCESS;
    }

    private function pgPartmanIsAvailable(): bool
    {
        return (bool) DB::selectOne("SELECT 1 AS installed FROM pg_extension WHERE extname = 'pg_partman'");
    }

    private function maintainFallback(string $table): void
    {
        $premake = (int) config('partitioning.premake');
        $start = Carbon::now()->startOfMonth();

        for ($i = 0; $i <= $premake; $i++) {
            $from = $start->copy()->addMonthsNoOverflow($i);
            $to = $from->copy()->addMonthNoOverflow();
            $partition = $table.'_'.$from->format('Y_m');

            DB::statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
                $partition,
                $table,
                $from->toDateTimeString(),
                $to->toDateTimeString(),
            ));
        }
    }
}
