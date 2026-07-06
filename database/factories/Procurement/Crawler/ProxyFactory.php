<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

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
            'targets' => null,
            'is_active' => true,
            'fails_count' => 0,
        ];
    }
}
