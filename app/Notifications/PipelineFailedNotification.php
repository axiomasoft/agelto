<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Notifications\Notification;

final class PipelineFailedNotification extends Notification
{
    public function __construct(
        private readonly string $stage,
        private readonly string $jobClass,
        private readonly int $jobId,
        private readonly string $errorMessage,
        private readonly int $attempt,
        private readonly int $maxAttempts,
    ) {}

    public function via(mixed $notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram(mixed $notifiable): string
    {
        $env = config('app.env') !== 'production' ? ' ['.mb_strtoupper((string) config('app.env')).']' : '';
        $shortError = mb_strimwidth($this->errorMessage, offset: 0, length: 300, trim: '…');

        return implode("\n", [
            "🔴 <b>[AGELTO{$env}] Сбой в пайплайне</b>",
            '',
            "Стадия: <b>{$this->stage}</b>",
            "Job: <code>{$this->jobClass} #{$this->jobId}</code>",
            "Попытка: {$this->attempt}/{$this->maxAttempts} (финальная)",
            '',
            "Ошибка: <code>{$shortError}</code>",
        ]);
    }
}
