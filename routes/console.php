<?php

use App\Enums\Crawler\CrawlerMode;
use App\Models\Crawler\Crawler;
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
// отсутствующей таблице `crw_crawlers`.
if (Schema::hasTable('crw_crawlers')) {
    foreach (Crawler::query()->where('mode', CrawlerMode::Fresh)->where('is_active', true)->get() as $crawler) {
        Schedule::command('crawler:run-fresh', [$crawler->id])->cron($crawler->schedule ?? '*/30 * * * *');
    }
}
