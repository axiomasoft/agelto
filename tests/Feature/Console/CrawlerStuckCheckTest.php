<?php

declare(strict_types=1);

use App\Enums\Crawler\CrawlerRunStatus;
use App\Models\Crawler\CrawlerRun;
use App\Notifications\PipelineAlertNotification;
use Illuminate\Support\Facades\Notification;

it('stuck-check алертит про зависший прогон краулера (устаревший heartbeat)', function () {
    Notification::fake();

    $crawler = crawlerProfile();
    CrawlerRun::query()->create([
        'crawler_id' => $crawler->id,
        'status' => CrawlerRunStatus::Running,
        'started_at' => now()->subHours(2),
        'last_heartbeat_at' => now()->subMinutes(30),
    ]);

    $this->artisan('app:pipeline:stuck-check')->assertSuccessful();

    Notification::assertSentOnDemand(PipelineAlertNotification::class);
});

it('stuck-check молчит про живой прогон краулера (свежий heartbeat)', function () {
    Notification::fake();

    $crawler = crawlerProfile();
    CrawlerRun::query()->create([
        'crawler_id' => $crawler->id,
        'status' => CrawlerRunStatus::Running,
        'started_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    $this->artisan('app:pipeline:stuck-check')
        ->expectsOutputToContain('Зависших задач не обнаружено.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});
