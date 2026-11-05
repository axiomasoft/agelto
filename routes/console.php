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

Schedule::command('partitions:maintain')->daily()->withoutOverlapping();
Schedule::call(ApplicationReadiness::recordSchedulerHeartbeat(...))->everyMinute();

// `$hasTable` — ленивый schema-guard внутри `when()`, который не обращается к БД при загрузке
// `routes/console.php` (на `composer install`, `package:discover` и старт воркеров) и не роняет
// `schedule:work` при временном отказе БД: отказ кэшируется на тик и логируется один раз без сырого
// текста исключения (чтобы не раскрывать DSN/секреты).
$hasTable = (static function (): Closure {
    $databaseAvailable = null;
    /** @var array<string, bool> $tableCache */
    $tableCache = [];

    return static function (string $table) use (&$databaseAvailable, &$tableCache): bool {
        if ($databaseAvailable === false) {
            return false;
        }
        if (array_key_exists($table, $tableCache)) {
            return $tableCache[$table];
        }

        try {
            $exists = Schema::hasTable($table);
            $databaseAvailable = true;
            $tableCache[$table] = $exists;

            return $exists;
        } catch (Throwable $exception) {
            $databaseAvailable = false;
            Log::warning('Планировщик: БД недоступна, задачи, зависящие от таблиц, пропущены.', [
                'error_code' => 'scheduler_schema_unavailable',
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    };
})();

Schedule::command('process:schedule-acquisition')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(static fn (): bool => $hasTable('prc_acquisition_schedules'));

// Delivery, recovery and document due production belong to durable daemons.
Schedule::command('process:retention')
    ->hourly()
    ->withoutOverlapping()
    ->when(static fn (): bool => $hasTable('prc_process_transitions'));
Schedule::command('process:health-report')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(static fn (): bool => $hasTable('prc_process_transitions'));
