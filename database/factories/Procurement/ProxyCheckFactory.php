<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\Proxy;
use App\Models\Procurement\ProxyCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyCheck>
 */
final class ProxyCheckFactory extends Factory
{
    protected $model = ProxyCheck::class;

    public function definition(): array
    {
        return [
            'proxy_id' => Proxy::factory(),
            'checked_at' => now(),
            'success' => true,
            'latency_ms' => fake()->numberBetween(int1: 10, int2: 2000),
            'error' => null,
        ];
    }
}
