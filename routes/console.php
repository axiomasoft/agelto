<?php

use App\Enums\Crawler\CrawlerMode;
use App\Models\Crawler\Crawler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('partitions:maintain')->daily();

foreach (Crawler::query()->where('mode', CrawlerMode::Fresh)->where('is_active', true)->get() as $crawler) {
    Schedule::command("crawler:run-fresh {$crawler->id}")->cron($crawler->schedule ?? '*/30 * * * *');
}
