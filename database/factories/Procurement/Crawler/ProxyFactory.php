<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

use App\Enums\Procurement\ProxyFailureReason;
use App\Enums\Procurement\ProxyScope;
use App\Models\Procurement\Crawler\Proxy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proxy>
 */
final class ProxyFactory extends Factory
{
    protected $model = Proxy::class;

    public function definition(): array
    {
        return [
            'service' => fake()->randomElement(['webshare', 'brightdata']),
            'host' => fake()->domainName(),
            'port' => fake()->numberBetween(int1: 1024, int2: 65535),
            'username' => fake()->userName(),
            'password' => fake()->password(),
            'country' => null,
            'scopes' => [],
            'is_active' => true,
            'fails_count' => 0,
            'open_until' => null,
            'open_count' => 0,
            'last_failure_reason' => null,
            'last_success_at' => null,
            'last_failure_at' => null,
        ];
    }

    public function country(string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'country' => $code,
        ]);
    }

    /**
     * @param  list<ProxyScope>  $scopes
     */
    public function withScopes(array $scopes): static
    {
        return $this->state(fn (array $attributes): array => [
            'scopes' => $scopes,
        ]);
    }

    /**
     * Узел с открытым глобальным breaker'ом (research/02 §7).
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'open_until' => now()->addMinutes(30),
            'open_count' => 1,
            'last_failure_reason' => ProxyFailureReason::NetworkTimeout->value,
            'last_failure_at' => now(),
        ]);
    }
}
