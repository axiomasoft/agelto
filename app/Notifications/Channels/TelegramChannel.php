<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TelegramChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $token || ! $chatId) {
            return;
        }

        /** @var \App\Notifications\PipelineFailedNotification $notification */
        $message = $notification->toTelegram($notifiable);

        Http::timeout(10)
            ->retry(times: 2, sleepMilliseconds: 500)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ])
            ->onError(function () use ($message): void {
                Log::error('TelegramChannel: не удалось отправить уведомление', ['message' => $message]);
            });
    }
}
