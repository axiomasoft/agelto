<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

use App\Enums\Procurement\Proxy\ProxyFailureReason;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\ProxyTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyTarget>
 */
final class ProxyTargetFactory extends Factory
{
    protected $model = ProxyTarget::class;

    public function definition(): array
    {
        return [
            'proxy_id' => Proxy::factory(),
            'target' => fake()->domainName(),
            'success_ratio' => 1.0,
            'latency_ms_avg' => null,
            'consecutive_failures' => 0,
            'open_until' => null,
            'open_count' => 0,
            'last_failure_reason' => null,
            'last_outcome_at' => null,
        ];
    }

    /**
     * Целевой breaker открыт (research/02 §7).
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'open_until' => now()->addMinutes(15),
            'open_count' => 1,
            'consecutive_failures' => 0,
            'last_failure_reason' => ProxyFailureReason::Http403->value,
            'last_outcome_at' => now(),
        ]);
    }
}
