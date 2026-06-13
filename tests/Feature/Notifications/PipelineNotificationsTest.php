<?php

declare(strict_types=1);

use App\Notifications\Channels\TelegramChannel;
use App\Notifications\PipelineAlertNotification;
use App\Notifications\PipelineFailedNotification;

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
