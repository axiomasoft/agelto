<?php

use App\Enums\Procurement\CrawlerMode;
use App\Models\Procurement\Crawler\Crawler;
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
    Schedule::command('crawler:fetch-details')->everyFiveMinutes();
}

// Тот же schema-guard (см. выше) — sweep читает `prc_crawler_runs.stats->cap_hit`.
if (Schema::hasTable('prc_crawler_runs')) {
    Schedule::command('crawler:rescan-backlog')->everyFifteenMinutes();
}
