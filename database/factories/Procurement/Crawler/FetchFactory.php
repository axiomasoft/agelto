<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fetch>
 */
final class FetchFactory extends Factory
{
    protected $model = Fetch::class;

    public function definition(): array
    {
        return [
            'crawler_run_id' => Run::factory(),
            'method' => 'csv',
            'request_params' => null,
            'artifact_ref' => null,
            'http_status' => 200,
            'duration_ms' => fake()->numberBetween(int1: 100, int2: 30000),
            'rows_total' => fake()->numberBetween(int1: 0, int2: 500),
            'rows_new' => fake()->numberBetween(int1: 0, int2: 100),
            'rows_updated' => fake()->numberBetween(int1: 0, int2: 100),
            'dedup_signature' => null,
            'proxy_id' => null,
            'fetched_at' => now(),
        ];
    }
}
