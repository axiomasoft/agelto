<?php

use App\Services\Health\ApplicationReadiness;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('partitions:maintain')->daily();
Schedule::call(ApplicationReadiness::recordSchedulerHeartbeat(...))->everyMinute();

// `$hasTable` — schema-guard, который не бросает при недоступной БД: файл выполняется на
// КАЖДЫЙ artisan-вызов (включая `package:discover` в `composer install` и старт воркеров), а
// `schedule:work` перечитывает его каждую минуту, поэтому временный отказ БД только пропускает
// задачи, зависящие от таблиц, а не роняет процесс. Отказ пишется в лог один раз за загрузку.
$hasTable = (static function (): Closure {
    $databaseAvailable = null;

    return static function (string $table) use (&$databaseAvailable): bool {
        if ($databaseAvailable === false) {
            return false;
        }

        try {
            $exists = Schema::hasTable($table);
            $databaseAvailable = true;

            return $exists;
        } catch (Throwable $exception) {
            $databaseAvailable = false;
            Log::warning('Планировщик: БД недоступна, задачи, зависящие от таблиц, пропущены.', ['exception' => $exception->getMessage()]);

            return false;
        }
    };
})();

if ($hasTable('prc_acquisition_schedules')) {
    Schedule::command('process:schedule-acquisition')->everyMinute()->withoutOverlapping();
}

// Delivery, recovery and document due production belong to durable daemons.
if ($hasTable('prc_process_transitions')) {
    Schedule::command('process:retention')->hourly()->withoutOverlapping();
    Schedule::command('process:health-report')->everyFiveMinutes()->withoutOverlapping();
}
