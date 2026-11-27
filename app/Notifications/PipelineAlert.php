<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Dto\Procurement\Network\Http\HttpRequestPayload;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Enums\Telemetry\LogChannel;
use App\Services\Procurement\Proxyma\Caller\TelegramOutbound;
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
    private const int MAX_MESSAGE_LENGTH = 4096;

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
        $ttl = now()->addMinutes($antispamMinutes);
        $due = [];
        $dueKeys = [];

        foreach ($findings as $key => $message) {
            $cacheKey = "pipeline_alert:{$key}";
            if (Cache::add(key: $cacheKey, value: true, ttl: $ttl)) {
                $due[] = $message;
                $dueKeys[] = $cacheKey;
            }
        }

        if ($due === []) {
            return;
        }

        if (! $this->send($this->buildMessage($due))) {
            foreach ($dueKeys as $cacheKey) {
                Cache::forget($cacheKey);
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

        $header = "⚠️ <b>[AGELTO{$env}] Проблемы пайплайна</b>\n\n";
        $footer = "\n\nПроверьте Horizon или запустите <code>php artisan process:status</code> / <code>php artisan pipeline:status</code>";
        $body = implode("\n", array_map(
            static fn (string $message): string => '• '.htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $messages,
        ));

        $maxBodyLength = self::MAX_MESSAGE_LENGTH - mb_strlen($header, 'UTF-8') - mb_strlen($footer, 'UTF-8');
        if (mb_strlen($body, 'UTF-8') > $maxBodyLength) {
            $body = mb_substr($body, 0, max(0, $maxBodyLength - 1), 'UTF-8');
            $body = preg_replace('/&[a-zA-Z0-9#]*$/u', '', $body) ?? $body;
            $body .= '…';
        }

        return $header.$body.$footer;
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
        } catch (\Throwable $e) {
            LogChannel::Alerts->error('PipelineAlert: не удалось отправить уведомление', [
                'message' => $message,
                'exception_class' => $e::class,
            ]);

            return false;
        }
    }
}
