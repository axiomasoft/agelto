<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Notifications\Notification;

final class PipelineAlertNotification extends Notification
{
    public function __construct(
        private readonly string $message,
    ) {}

    public function via(mixed $notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram(mixed $notifiable): string
    {
        return $this->message;
    }
}
