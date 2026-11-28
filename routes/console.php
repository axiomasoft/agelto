<?php

declare(strict_types=1);

use App\Enums\Telemetry\LogChannel;
use App\Services\Health\ApplicationReadiness;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Proxyma\Infrastructure\Database\PackageSchema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('guard:sync-roles', function () {
    $this->comment('Roles are code-defined in AzGuard 2.0.');
})->purpose('No-op compatibility alias for AzGuard 2.0 code-defined roles');

Schedule::command('partitions:maintain')->daily()->withoutOverlapping();
Schedule::call(ApplicationReadiness::recordSchedulerHeartbeat(...))->everyMinute();

// `$hasTable` — ленивый schema-guard внутри `when()`, который не обращается к БД при загрузке
// `routes/console.php` (на `composer install`, `package:discover` и старт воркеров) и не роняет
// `schedule:work` при временном отказе БД: отказ кэшируется на тик и логируется один раз без сырого
// текста исключения (чтобы не раскрывать DSN/секреты).
$hasTable = (static function (): Closure {
    /** @var array<string, true> $unavailableConnections */
    $unavailableConnections = [];
    /** @var array<string, bool> $tableCache */
    $tableCache = [];

    return static function (string $table, ?string $connection = null) use (&$unavailableConnections, &$tableCache): bool {
        $connection ??= DB::getDefaultConnection();
        if (isset($unavailableConnections[$connection])) {
            return false;
        }
        $cacheKey = $connection.':'.$table;
        if (array_key_exists($cacheKey, $tableCache)) {
            return $tableCache[$cacheKey];
        }

        try {
            $exists = Schema::connection($connection)->hasTable($table);
            $tableCache[$cacheKey] = $exists;

            return $exists;
        } catch (Throwable $exception) {
            $unavailableConnections[$connection] = true;
            LogChannel::Alerts->warning('Планировщик: БД недоступна, задачи, зависящие от таблиц, пропущены.', [
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
Schedule::command('proxyma:prune')
    ->hourly()
    ->withoutOverlapping()
    ->when(static fn (): bool => $hasTable(table: PackageSchema::tables()['operations'], connection: Config::string('proxyma.connection')));
Schedule::command('process:health-report')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(static fn (): bool => $hasTable('prc_process_transitions'));
Schedule::command('process:diagnostics')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(static fn (): bool => $hasTable('prc_process_transitions'));
