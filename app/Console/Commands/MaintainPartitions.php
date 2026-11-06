<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\PartitionMaintenanceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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
 * `RefreshDatabase`).
 */
#[Description('Premake партиций на 3 месяца вперёд для партиционированных таблиц (pg_partman либо fallback)')]
#[Signature('partitions:maintain')]
final class MaintainPartitions extends Command
{
    public function handle(PartitionMaintenanceService $service = new PartitionMaintenanceService): int
    {
        return DB::transaction(function () use ($service): int {
            if (! $service->acquireAdvisoryLock()) {
                $this->info('Обслуживание партиций уже выполняется другим процессом.');

                return self::SUCCESS;
            }

            if ($service->maintain()) {
                $this->info('Партиции обновлены через pg_partman (run_maintenance, вкл. retention-DROP по part_config).');

                return self::SUCCESS;
            }

            $this->info('pg_partman недоступен — партиции досозданы и просрочённые дропнуты fallback-командой.');

            return self::SUCCESS;
        });
    }
}
