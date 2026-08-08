<?php

use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\PipelineDispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('partitions:maintain')->daily();

// Schema-guard: этот файл выполняется на КАЖДЫЙ artisan-вызов (не только schedule:run),
// в т.ч. до первой миграции (`migrate` на свежем окружении) — без гварда упал бы на
// отсутствующей таблице `prc_crawlers`.
if (Schema::hasTable('prc_crawlers')) {
    foreach (Crawler::query()->where('mode', CrawlerMode::Fresh)->where('is_active', true)->get() as $crawler) {
        Schedule::command('crawler:run-fresh', [$crawler->id])->cron($crawler->schedule ?? '*/30 * * * *');
    }
}

// Тот же schema-guard (см. выше) — детальная стадия читает `prc_procurements`.
if (Schema::hasTable('prc_procurements')) {
    Schedule::command('crawler:fetch-details')->name('crawler:fetch-details')->everyFiveMinutes()->withoutOverlapping();
}

// Тот же schema-guard (см. выше) — sweep читает `prc_crawler_runs.stats->cap_hit`.
if (Schema::hasTable('prc_crawler_runs')) {
    Schedule::command('crawler:rescan-backlog')->everyFifteenMinutes();
}

// Тот же schema-guard (см. выше) — подъём зависших running-прогонов (P3.2, D28): павший
// воркер оставляет прогон в running навсегда, recoverStale() был мёртвым кодом, никем не
// вызывался.
if (Schema::hasTable('prc_crawler_runs')) {
    Schedule::command('crawler:recover-stale')->everyFiveMinutes();
}

// Эталон и история healthcheck существуют только после P5.1; до миграций console.php всё так же
// безопасно загружается для любого artisan-вызова.
if (Schema::hasTable('prc_platform_checks')) {
    Schedule::command('crawler:health-check')
        ->cron((string) config('crawler.health.cron'))
        ->withoutOverlapping();
}

// Тот же schema-guard (см. выше) — плановый delta-scan окна обновления (П-3,
// `research/05-change-tracking.md` §2.2); выключен по умолчанию — включает владелец
// после боевого прогона P4.7 (как и fresh-профиль, D37).
if (Schema::hasTable('prc_crawlers') && config('crawler.tracking.delta_scan_enabled')) {
    Schedule::command('crawler:run-delta')->cron((string) config('crawler.tracking.delta_scan_cron'));
}

// Тот же schema-guard (см. выше) — мост подготовки документов (P4.3, ТЗ 03 §4). Поллер —
// страховка (research/L finding 12): прямой dispatch() уже происходит сразу после downloaded
// из `crawler:fetch-details`; этот тик добирает пропущенное/несработавшее по ВСЕМ закупкам.
if (Schema::hasTable('prc_documents')) {
    Schedule::call(function (PipelineDispatcher $dispatcher): void {
        $dispatcher->dispatchBacklog(limit: (int) config('ai.pipeline.backlog_batch_limit', 100));
    })->name('pipeline:dispatch-backlog')->everyMinute()->withoutOverlapping();

    Schedule::command('pipeline:retry-failed')->everyTenMinutes()->withoutOverlapping();

    // Наблюдаемость пайплайна (P7.3, ТЗ 03 §5, v1-канон HEARTBEAT 15м) — read-only, только
    // сигналит (антиспам-агрегация — `PipelineAlert`).
    Schedule::command('pipeline:stuck-check')->everyFifteenMinutes()->withoutOverlapping();
}
