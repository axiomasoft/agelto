<?php

declare(strict_types=1);

namespace App\Enums\Telemetry;

use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;

/**
 * Доменные каналы системного логирования и ранжирование событий по критичности.
 *
 * Базовый канал (`single`/`daily` -> `laravel.log`) остаётся только для обычных событий
 * приложения, а специализированные подсистемы пишут в изолированные каналы:
 * - `process`: переходы состояний Process Core, ошибки демонов, карантин, отказы доставки
 * - `network`: исходящие HTTP-попытки, circuit breaker, очистка временных артефактов транспорта
 * - `platforms`: разбор HTML/DOM карточек площадок и диагностика расхождения вёрстки
 * - `procurement`: инжест ревизий, проекции карточек закупок, расписание профилей сбора
 * - `storage`: резолвинг адресов объектов и срабатывание фолбэк-цепочки дисков
 * - `alerts`: доставка алертов пайплайна и доступность инфраструктуры планировщика
 *
 * Каждое системное событие ранжируется по уровню (`telemetry` / `degradation` / `incident`):
 * события ранга `degradation` (`warning`) дублируются в `system_warnings`,
 * а события ранга `incident` (`error`..`emergency`) — в `system_errors`.
 */
enum LogChannel: string
{
    case Process = 'process';
    case Network = 'network';
    case Platforms = 'platforms';
    case Procurement = 'procurement';
    case Storage = 'storage';
    case Alerts = 'alerts';

    public const string RANK_TELEMETRY = 'telemetry';

    public const string RANK_DEGRADATION = 'degradation';

    public const string RANK_INCIDENT = 'incident';

    public const string WARNINGS_CHANNEL = 'system_warnings';

    public const string ERRORS_CHANNEL = 'system_errors';

    public static function rankForLevel(string $level): string
    {
        return match (strtolower($level)) {
            'emergency', 'alert', 'critical', 'error' => self::RANK_INCIDENT,
            'warning' => self::RANK_DEGRADATION,
            default => self::RANK_TELEMETRY,
        };
    }

    public static function rankedChannelForLevel(string $level): ?string
    {
        return match (self::rankForLevel($level)) {
            self::RANK_INCIDENT => self::ERRORS_CHANNEL,
            self::RANK_DEGRADATION => self::WARNINGS_CHANNEL,
            default => null,
        };
    }

    /**
     * @param  array<int|string, mixed>  $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->dispatch('info', $message, $context, specificMethod: true);
    }

    /**
     * @param  array<int|string, mixed>  $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->dispatch('warning', $message, $context, specificMethod: true);
    }

    /**
     * @param  array<int|string, mixed>  $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->dispatch('error', $message, $context, specificMethod: true);
    }

    /**
     * @param  array<int|string, mixed>  $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->dispatch($level, $message, $context, specificMethod: false);
    }

    /**
     * @param  array<int|string, mixed>  $context
     */
    private function dispatch(string $level, string $message, array $context, bool $specificMethod): void
    {
        $root = Log::getFacadeRoot();

        if (! $root instanceof LogManager || method_exists($root, 'mockery_init')) {
            if ($specificMethod) {
                match ($level) {
                    'info' => Log::info($message, $context),
                    'warning' => Log::warning($message, $context),
                    'error' => Log::error($message, $context),
                    default => Log::log($level, $message, $context),
                };

                return;
            }

            Log::log($level, $message, $context);

            return;
        }

        $rank = self::rankForLevel($level);
        $enriched = [
            ...$context,
            'log_domain' => $context['log_domain'] ?? $this->value,
            'log_rank' => $context['log_rank'] ?? $rank,
        ];

        self::writeChannel($root, $this->value, $level, $message, $enriched);

        $rankedChannel = self::rankedChannelForLevel($level);
        if ($rankedChannel !== null && is_array(config("logging.channels.{$rankedChannel}"))) {
            self::writeChannel($root, $rankedChannel, $level, $message, $enriched);
        }
    }

    /**
     * @param  array<int|string, mixed>  $context
     */
    private static function writeChannel(LogManager $root, string $channel, string $level, string $message, array $context): void
    {
        $testLogRoot = config('testing.log_root');
        if (is_string($testLogRoot) && $testLogRoot !== '') {
            $currentPath = config("logging.channels.{$channel}.path");
            if (is_string($currentPath) && ! str_starts_with($currentPath, $testLogRoot.'/')) {
                config()->set("logging.channels.{$channel}.path", $testLogRoot.'/'.basename($currentPath));
                $root->forgetChannel($channel);
            }
        }

        $root->channel($channel)->log($level, $message, $context);
    }
}
