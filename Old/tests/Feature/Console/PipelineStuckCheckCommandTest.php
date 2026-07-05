<?php

declare(strict_types=1);

use App\Models\Crawler\CrawlerRun;
use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
use App\Notifications\PipelineAlertNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;

test('молчит, когда зависших задач нет', function () {
    Notification::fake();

    $this->artisan('app:pipeline:stuck-check')
        ->expectsOutputToContain('Зависших задач не обнаружено.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

test('шлёт алерт при зависшей ссылке', function () {
    Notification::fake();

    // Ссылка в PROCESSING, висящая дольше порога зависания.
    $link = Link::factory()->processing()->create();
    $link->forceFill(['updated_at' => now()->subHours(3)])->saveQuietly();

    $this->artisan('app:pipeline:stuck-check')->assertSuccessful();

    Notification::assertSentOnDemand(PipelineAlertNotification::class);
});

test('шлёт алерт про вложения без анализа (разрыв конвейера)', function () {
    Notification::fake();

    // Вложение без анализа висит дольше порога — конвейер должен был его разобрать.
    $attachment = LinkAttachment::factory()->for(Link::factory()->pending())->create();
    $attachment->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    $this->artisan('app:pipeline:stuck-check')->assertSuccessful();

    Notification::assertSentOnDemand(PipelineAlertNotification::class);
});

test('шлёт алерт про зависший прогон краулера (устаревший heartbeat)', function () {
    Notification::fake();

    CrawlerRun::factory()->create([
        'started_at' => now()->subHours(2),
        'last_heartbeat_at' => now()->subMinutes(30),
    ]);

    $this->artisan('app:pipeline:stuck-check')->assertSuccessful();

    Notification::assertSentOnDemand(PipelineAlertNotification::class);
});

test('молчит про живой прогон краулера (свежий heartbeat)', function () {
    Notification::fake();

    CrawlerRun::factory()->create();

    $this->artisan('app:pipeline:stuck-check')
        ->expectsOutputToContain('Зависших задач не обнаружено.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

test('шлёт алерт при недоступном Redis (очереди на redis)', function () {
    config(['queue.default' => 'redis']);
    Notification::fake();

    Redis::shouldReceive('connection')->andThrow(new RuntimeException('Connection refused'));

    $this->artisan('app:pipeline:stuck-check')->assertSuccessful();

    Notification::assertSentOnDemand(PipelineAlertNotification::class);
});

test('не проверяет Redis, когда очереди не на redis (sync)', function () {
    config(['queue.default' => 'sync']);
    Notification::fake();

    // Если бы Redis всё-таки дёргали — упало бы; ожидаем «чисто».
    $this->artisan('app:pipeline:stuck-check')
        ->expectsOutputToContain('Зависших задач не обнаружено.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});
