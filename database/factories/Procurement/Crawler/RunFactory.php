<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

use App\Enums\Procurement\RunStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
final class RunFactory extends Factory
{
    protected $model = Run::class;

    public function definition(): array
    {
        return [
            'crawler_id' => Crawler::factory(),
            'status' => RunStatus::Running,
            'cursor' => null,
            'stats' => null,
            'started_at' => now(),
            'finished_at' => null,
            'last_heartbeat_at' => now(),
            'attempts' => 0,
            'last_error' => null,
            'last_error_at' => null,
            'next_retry_at' => null,
            'failed_permanently' => false,
        ];
    }
}
