<?php

declare(strict_types=1);

use App\Notifications\PipelineAlertNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;

it('stuck-check алертит при недоступном Redis (очереди на redis)', function () {
    config(['queue.default' => 'redis']);
    Notification::fake();

    Redis::shouldReceive('connection')->andThrow(new RuntimeException('Connection refused'));

    $this->artisan('app:pipeline:stuck-check')->assertSuccessful();

    Notification::assertSentOnDemand(PipelineAlertNotification::class);
});

it('stuck-check не проверяет Redis, когда очереди не на redis (sync)', function () {
    config(['queue.default' => 'sync']);
    Notification::fake();

    // Если бы Redis всё-таки дёргали — упало бы; ожидаем «чисто».
    $this->artisan('app:pipeline:stuck-check')
        ->expectsOutputToContain('Зависших задач не обнаружено.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});
