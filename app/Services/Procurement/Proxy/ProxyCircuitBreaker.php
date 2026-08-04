<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Enums\Procurement\Proxy\ProxyFailureReason;
use Carbon\CarbonImmutable;

/**
 * Автомат `closed → open → half-open → closed` на два уровня (research/02 §5, §7). Чистая
 * логика без персистентности — состояние читает/пишет `ProxyResolver` через
 * `ProxyHealthStoreRepository`; единственность half-open пробы гарантирует условный `UPDATE`
 * репозитория, не эта логика.
 */
final readonly class ProxyCircuitBreaker
{
    public const string LevelGlobal = 'global';

    public const string LevelTarget = 'target';

    /**
     * Уровень реакции по таксономии причин (research/02 §5) — сетевые/креды/tls свойства УЗЛА
     * (глобальный уровень), ответ ЦЕЛИ (403/429/пустое тело/неизвестное) — свойство пары
     * (узел, цель).
     */
    public function level(ProxyFailureReason $reason): string
    {
        return match ($reason) {
            ProxyFailureReason::NetworkTimeout,
            ProxyFailureReason::TlsError,
            ProxyFailureReason::ConnectFailed,
            ProxyFailureReason::ProxyAuthFailed => self::LevelGlobal,
            ProxyFailureReason::Http403,
            ProxyFailureReason::Http429,
            ProxyFailureReason::EmptyBody,
            ProxyFailureReason::Unknown => self::LevelTarget,
        };
    }

    /**
     * @return array{consecutive_failures: int, open_count: int, open_until: ?CarbonImmutable}
     */
    public function onFailure(int $consecutiveFailures, int $openCount, ProxyFailureReason $reason): array
    {
        $level = $this->level($reason);
        $consecutiveFailures++;

        if ($consecutiveFailures < $this->threshold($level, $reason)) {
            return [
                'consecutive_failures' => $consecutiveFailures,
                'open_count' => $openCount,
                'open_until' => null,
            ];
        }

        $openCount++;
        $cooldownMinutes = min(
            $this->baseCooldownMinutes($level, $reason) * 2 ** ($openCount - 1),
            $this->capMinutes(),
        );

        return [
            'consecutive_failures' => 0,
            'open_count' => $openCount,
            'open_until' => CarbonImmutable::now()->addMinutes($cooldownMinutes),
        ];
    }

    /**
     * @return array{consecutive_failures: 0, open_count: 0, open_until: null}
     */
    public function onSuccess(): array
    {
        return ['consecutive_failures' => 0, 'open_count' => 0, 'open_until' => null];
    }

    private function threshold(string $level, ProxyFailureReason $reason): int
    {
        if ($level === self::LevelTarget && $reason === ProxyFailureReason::Http429) {
            return (int) config('crawler.proxy.breaker.http_429.threshold');
        }

        return (int) config("crawler.proxy.breaker.threshold.{$level}");
    }

    private function baseCooldownMinutes(string $level, ProxyFailureReason $reason): int
    {
        if ($level === self::LevelTarget && $reason === ProxyFailureReason::Http429) {
            return (int) config('crawler.proxy.breaker.http_429.base_cooldown_minutes');
        }

        return (int) config("crawler.proxy.breaker.base_cooldown_minutes.{$level}");
    }

    private function capMinutes(): int
    {
        return (int) config('crawler.proxy.breaker.cap_hours') * 60;
    }
}
