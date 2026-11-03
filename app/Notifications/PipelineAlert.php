<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Единая точка отправки алертов пайплайна (`pipeline:stuck-check`, P7.3) — Telegram-транспорт,
 * аналогичный v1 (`Old/app/Notifications/{PipelineAlert,PipelineAlertNotification,
 * Channels/TelegramChannel}.php`), консолидированный в ОДИН файл (Files-список item'а несёт
 * ровно этот путь): Bot API напрямую через `Http`, без Laravel Notification/Channel-индирекции
 * — единственный канал, единственный тип сообщения, вторая абстракция не оправдана (YAGNI).
 * Канал/бот — `config('services.telegram.*')`; конфиг не задан → алерт уходит только в лог
 * (Implementation Rules: команда read-only, но находка не теряется бесследно).
 *
 * Антиспам — per-finding кэш-ключ (Code Guidance: «не чаще раза в час по одинаковой находке»):
 * `notify()` принимает `array<string, string>` (ключ находки → человекочитаемое сообщение),
 * пропускает через `Cache::add()` только находки, ещё не отмеченные в текущем окне
 * (`config('process.diagnostic_thresholds.alert_antispam_minutes')`), и отправляет ОДНО агрегированное сообщение
 * по оставшимся — если все находки тика уже были отмечены раньше, тишина (не дублируем алерт).
 */
final readonly class PipelineAlert
{
    /**
     * @param  array<string, string>  $findings  ключ находки → человекочитаемое сообщение
     */
    public function notify(array $findings): void
    {
        if ($findings === []) {
            return;
        }

        $configuredMinutes = config('process.diagnostic_thresholds.alert_antispam_minutes', 60);
        $antispamMinutes = is_int($configuredMinutes) || is_float($configuredMinutes) || is_string($configuredMinutes)
            ? (int) $configuredMinutes
            : 60;
        $due = [];

        foreach ($findings as $key => $message) {
            if (Cache::add(key: "pipeline_alert:{$key}", value: true, ttl: now()->addMinutes($antispamMinutes))) {
                $due[] = $message;
            }
        }

        if ($due === []) {
            return;
        }

        $this->send($this->buildMessage($due));
    }

    /**
     * @param  list<string>  $messages
     */
    private function buildMessage(array $messages): string
    {
        $configuredEnv = config('app.env');
        $envName = is_string($configuredEnv) ? $configuredEnv : 'unknown';
        $env = $envName !== 'production' ? ' ['.mb_strtoupper($envName).']' : '';

        return implode("\n", [
            "⚠️ <b>[AGELTO{$env}] Проблемы пайплайна</b>",
            '',
            ...array_map(static fn (string $message): string => '• '.$message, $messages),
            '',
            'Проверьте Horizon или запустите <code>php artisan pipeline:status</code>',
        ]);
    }

    private function send(string $message): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! is_string($token) || $token === '' || ! is_string($chatId) || $chatId === '') {
            Log::warning('PipelineAlert: Telegram канал не настроен, алерт отправлен в лог', ['message' => $message]);

            return;
        }

        Http::timeout(10)
            ->retry(times: 2, sleepMilliseconds: 500)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ])
            ->onError(function () use ($message): void {
                Log::error('PipelineAlert: не удалось отправить уведомление', ['message' => $message]);
            });
    }
}
