<?php

use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Health\ApplicationReadiness;
use App\Services\Procurement\DocumentRevalidationSelector;
use App\Services\Procurement\PipelineDispatcher;
use App\Services\Procurement\PipelineRecoveryReconciler;
use Cron\CronExpression;
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

// Schema-guard: этот файл выполняется на КАЖДЫЙ artisan-вызов (не только schedule:run),
// в т.ч. до первой миграции (`migrate` на свежем окружении) — без гварда упал бы на
// отсутствующей таблице `prc_crawlers`.
//
// Расписание fresh-профилей читается из БД, поэтому ошибка здесь не должна ронять ни один
// artisan-вызов (`package:discover`, `migrate`, воркеры) и остальные задачи планировщика:
// недоступная БД пропускает только профили (`schedule:work` перечитает их через минуту), а
// невалидный cron одного профиля — только этот профиль. Пустое расписание — профиль не
// запускается по cron (контракт формы краулера), а не скрытый «каждые 30 минут».
$freshCrawlers = $hasTable('prc_crawlers')
    ? Crawler::query()->where('mode', CrawlerMode::Fresh)->where('is_active', true)->get()
    : collect();

foreach ($freshCrawlers as $crawler) {
    $cron = trim((string) $crawler->schedule);

    if ($cron === '') {
        continue;
    }

    if (! CronExpression::isValidExpression($cron)) {
        Log::error('Fresh-профиль пропущен планировщиком: невалидное cron-выражение.', ['crawler_id' => $crawler->id, 'schedule' => $cron]);

        continue;
    }

    Schedule::command('crawler:run-fresh', [$crawler->id])->cron($cron);
}

// Тот же schema-guard (см. выше) — детальная стадия читает `prc_procurements`.
if ($hasTable('prc_procurements')) {
    Schedule::command('crawler:fetch-details')->name('crawler:fetch-details')->everyFiveMinutes()->withoutOverlapping();
}

// Тот же schema-guard (см. выше) — sweep читает `prc_crawler_runs.stats->cap_hit`.
if ($hasTable('prc_crawler_runs')) {
    Schedule::command('crawler:rescan-backlog')
        ->name('crawler:rescan-backlog')
        ->everyFifteenMinutes()
        ->withoutOverlapping();
}

// Тот же schema-guard (см. выше) — подъём зависших running-прогонов (P3.2, D28): павший
// воркер оставляет прогон в running навсегда, recoverStale() был мёртвым кодом, никем не
// вызывался.
if ($hasTable('prc_crawler_runs')) {
    Schedule::command('crawler:recover-stale')->everyFiveMinutes();
}

// Эталон и история healthcheck существуют только после P5.1; до миграций console.php всё так же
// безопасно загружается для любого artisan-вызова.
if (config('crawler.health.enabled') && $hasTable('prc_platform_checks')) {
    Schedule::command('crawler:health-check')
        ->cron((string) config('crawler.health.cron'))
        ->withoutOverlapping();
}

// Тот же schema-guard (см. выше) — плановый delta-scan окна обновления (П-3,
// `research/05-change-tracking.md` §2.2); выключен по умолчанию — включает владелец
// после боевого прогона P4.7 (как и fresh-профиль, D37).
if ($hasTable('prc_crawlers') && config('crawler.tracking.delta_scan_enabled')) {
    Schedule::command('crawler:run-delta')
        ->name('crawler:run-delta')
        ->cron((string) config('crawler.tracking.delta_scan_cron'))
        ->withoutOverlapping();
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
