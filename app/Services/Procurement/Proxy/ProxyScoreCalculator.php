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
        $weights = config('crawler.proxy.resolver.weights');
        $latencyRefMs = (float) config('crawler.proxy.resolver.latency_ref_ms');
        $streakRef = (float) config('crawler.proxy.resolver.streak_ref');

        $latencyNorm = $candidate['latency_ms_avg'] === null
            ? 0.0
            : min($candidate['latency_ms_avg'] / $latencyRefMs, 1.0);

        $streakNorm = min($candidate['fails_count'] / $streakRef, 1.0);

        return $weights['success'] * $candidate['success_ratio']
            - $weights['latency'] * $latencyNorm
            - $weights['streak'] * $streakNorm;
    }
}
