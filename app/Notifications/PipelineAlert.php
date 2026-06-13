<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Централизованная отправка алерта о провале стадии с троттлингом —
 * чтобы массовый сбой не порождал шквал уведомлений (анти-спам/ratelimit Telegram).
 */
final class PipelineAlert
{
    private const THROTTLE_MINUTES = 5;

    public static function failure(string $stage, string $jobClass, int $jobId, string $message, int $attempt, int $maxAttempts): void
    {
        // Не чаще одного алерта на стадию в окне троттлинга.
        if (! Cache::add('pipeline_alert:'.md5($stage), true, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        Notification::route(TelegramChannel::class, null)
            ->notify(new PipelineFailedNotification(
                stage: $stage,
                jobClass: $jobClass,
                jobId: $jobId,
                errorMessage: $message,
                attempt: $attempt,
                maxAttempts: $maxAttempts,
            ));
    }
}
