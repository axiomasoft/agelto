<?php

use App\Services\Health\ApplicationReadiness;
use App\Services\Procurement\DocumentRevalidationSelector;
use App\Services\Procurement\PipelineDispatcher;
use App\Services\Procurement\PipelineRecoveryReconciler;
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

// Тот же schema-guard (см. выше) — мост подготовки документов (P4.3, ТЗ 03 §4). Поллер —
// страховка (research/L finding 12): прямой dispatch() уже происходит сразу после downloaded
// из `crawler:fetch-details`; этот тик добирает пропущенное/несработавшее по ВСЕМ закупкам.
if ($hasTable('prc_documents')) {
    Schedule::call(function (DocumentRevalidationSelector $selector): void {
        $selector->dispatchDue(limit: (int) config('crawler.documents_revalidation_batch_limit', 25));
    })->name('documents:revalidate-due')->cron('17 */6 * * *')->withoutOverlapping();

    Schedule::call(function (PipelineDispatcher $dispatcher): void {
        $dispatcher->dispatchBacklog(limit: (int) config('ai.pipeline.backlog_batch_limit', 100));
    })->name('pipeline:dispatch-backlog')->everyMinute()->withoutOverlapping();

    Schedule::command('pipeline:retry-failed')->everyTenMinutes()->withoutOverlapping();
    Schedule::command('pipeline:cleanup-temp')->hourly()->withoutOverlapping();

    Schedule::call(function (PipelineRecoveryReconciler $reconciler): void {
        $reconciler->reconcile(limit: (int) config('ai.pipeline.retry_batch_limit', 50));
    })->name('pipeline:recover-continuations')->everyMinute()->withoutOverlapping();

    // Наблюдаемость пайплайна (P7.3, ТЗ 03 §5, v1-канон HEARTBEAT 15м) — read-only, только
    // сигналит (антиспам-агрегация — `PipelineAlert`).
    Schedule::command('pipeline:stuck-check')->everyFifteenMinutes()->withoutOverlapping();
}

// Bounded retention runs separately from HTTP workers.
if ($hasTable('prc_raw_artifacts')) {
    Schedule::command('crawler:prune-history')->hourly()->withoutOverlapping();
}
