<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

/**
 * Формула score резолвера (research/02 §6): `w_success*success_ratio - w_latency*latency_norm
 * - w_streak*streak_norm`. Веса и точки отсчёта — `config/crawler.php` (некалиброваны, P4.7).
 */
final readonly class ProxyScoreCalculator
{
    /**
     * @param  array{success_ratio: float, latency_ms_avg: ?int, fails_count: int}  $candidate
     */
    public function score(array $candidate): float
    {
        $weights = $this->weights();
        $latencyRefMs = $this->positiveFloatConfig('crawler.proxy.resolver.latency_ref_ms');
        $streakRef = $this->positiveFloatConfig('crawler.proxy.resolver.streak_ref');

        $latencyNorm = $candidate['latency_ms_avg'] === null
            ? 0.0
            : min($candidate['latency_ms_avg'] / $latencyRefMs, 1.0);

        $streakNorm = min($candidate['fails_count'] / $streakRef, 1.0);

        return $weights['success'] * $candidate['success_ratio']
            - $weights['latency'] * $latencyNorm
            - $weights['streak'] * $streakNorm;
    }

    /** @return array{success: float, latency: float, streak: float} */
    private function weights(): array
    {
        $configured = config('crawler.proxy.resolver.weights');

        if (! is_array($configured)) {
            throw new \LogicException('crawler.proxy.resolver.weights должен быть массивом весов');
        }

        $weights = [];

        foreach (['success', 'latency', 'streak'] as $key) {
            $value = $configured[$key] ?? null;

            if (! is_int($value) && ! is_float($value)) {
                throw new \LogicException("crawler.proxy.resolver.weights.{$key} должен быть числом");
            }

            $weights[$key] = (float) $value;
        }

        return $weights;
    }

    private function positiveFloatConfig(string $key): float
    {
        $value = config($key);

        if ((! is_int($value) && ! is_float($value)) || $value <= 0) {
            throw new \LogicException("{$key} должен быть положительным числом");
        }

        return (float) $value;
    }
}
