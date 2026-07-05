<?php

declare(strict_types=1);

use App\Notifications\Channels\TelegramChannel;
use App\Notifications\PipelineAlert;
use App\Notifications\PipelineAlertNotification;
use App\Notifications\PipelineFailedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

test('PipelineFailedNotification идёт в Telegram и содержит стадию, job и ошибку', function () {
    $notification = new PipelineFailedNotification(
        stage: 'Estimate Analysis',
        jobClass: 'App\\Process\\EstimateAnalysis\\Jobs\\EstimateAnalysisJob',
        jobId: 42,
        errorMessage: 'Gemini timeout',
        attempt: 3,
        maxAttempts: 3,
    );

    expect($notification->via(notifiable: null))->toBe([TelegramChannel::class]);

    $message = $notification->toTelegram(notifiable: null);

    expect($message)
        ->toContain('Estimate Analysis')
        ->toContain('#42')
        ->toContain('Gemini timeout')
        ->toContain('3/3');
});

test('PipelineAlertNotification возвращает переданное сообщение как есть', function () {
    $notification = new PipelineAlertNotification(message: 'Зависших анализов: 5');

    expect($notification->via(notifiable: null))->toBe([TelegramChannel::class])
        ->and($notification->toTelegram(notifiable: null))->toBe('Зависших анализов: 5');
});

test('TelegramChannel при отсутствии конфига пишет алерт в лог, а не молчит', function () {
    config(['services.telegram.bot_token' => null, 'services.telegram.chat_id' => null]);
    Log::spy();

    (new TelegramChannel())->send(notifiable: null, notification: new PipelineAlertNotification(message: 'тест-алерт'));

    Log::shouldHaveReceived('warning')->once();
});

test('PipelineAlert троттлит: на стадию не больше одного алерта в окне', function () {
    Cache::flush();
    Notification::fake();

    PipelineAlert::failure('Primary Analysis', 'Job', 1, 'boom', 1, 5);
    PipelineAlert::failure('Primary Analysis', 'Job', 2, 'boom2', 2, 5);
    PipelineAlert::failure('Document Prepare', 'Job', 3, 'boom3', 1, 3);

    // Две Primary схлопнулись в один алерт, Document Prepare — отдельный.
    Notification::assertSentOnDemandTimes(PipelineFailedNotification::class, 2);
});
