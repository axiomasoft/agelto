<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\SafeUrl;
use App\Enums\Telemetry\LogChannel;
use App\Services\Procurement\Proxyma\TelegramOutbound;
use Illuminate\Support\Facades\Cache;

/**
 * Алерты пайплайна отправляются одним агрегированным Telegram-сообщением через Bot API.
 * Канал и бот задаются в `config('services.telegram.*')`; без конфигурации находка остаётся в логе.
 *
 * `notify()` принимает ключ находки и сообщение. `Cache::add()` пропускает только находки,
 * которые ещё не отмечены в окне `process.diagnostic_thresholds.alert_antispam_minutes`.
 * Если все находки уже отмечены, повторное сообщение не отправляется.
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
        $dueKeys = [];

        foreach ($findings as $key => $message) {
            $cacheKey = "pipeline_alert:{$key}";
            if (! Cache::has($cacheKey)) {
                $due[] = $message;
                $dueKeys[] = $cacheKey;
            }
        }

        if ($due === []) {
            return;
        }

        if ($this->send($this->buildMessage($due))) {
            $ttl = now()->addMinutes($antispamMinutes);
            foreach ($dueKeys as $cacheKey) {
                Cache::put(key: $cacheKey, value: true, ttl: $ttl);
            }
        }
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
            'Проверьте Horizon или запустите <code>php artisan process:status</code> / <code>php artisan pipeline:status</code>',
        ]);
    }

    private function send(string $message): bool
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! is_string($token) || $token === '' || ! is_string($chatId) || $chatId === '') {
            LogChannel::Alerts->warning('PipelineAlert: Telegram канал не настроен, алерт отправлен в лог', ['message' => $message]);

            return true;
        }

        try {
            $apiUrl = config('services.telegram.api_url', 'https://api.telegram.org');
            if (! is_string($apiUrl)) {
                throw new \InvalidArgumentException('Invalid Telegram API origin.');
            }

            return app(TelegramOutbound::class)->send(
                new SafeUrl(rtrim($apiUrl, '/')."/bot{$token}/sendMessage"),
                new HttpRequestPayload(['content-type' => 'application/json'], json_encode([
                    'chat_id' => $chatId, 'text' => $message, 'parse_mode' => 'HTML',
                ], JSON_THROW_ON_ERROR)));
        } catch (\Throwable) {
            LogChannel::Alerts->error('PipelineAlert: не удалось отправить уведомление', ['message' => $message]);

            return false;
        }
    }
}
