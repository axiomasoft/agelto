<?php

declare(strict_types=1);

namespace Database\Factories\Crawler;

use App\Enums\Crawler\CrawlerRunStatus;
use App\Models\Crawler\Crawler;
use App\Models\Crawler\CrawlerRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawlerRun>
 */
final class CrawlerRunFactory extends Factory
{
    protected $model = CrawlerRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'crawler_id' => Crawler::factory(),
            'status' => CrawlerRunStatus::Running,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ];
    }

    /** Живой прогон со свежим heartbeat. */
    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => CrawlerRunStatus::Running,
            'last_heartbeat_at' => now(),
        ]);
    }

    /** Штатно завершённый прогон. */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => CrawlerRunStatus::Completed,
            'finished_at' => now(),
        ]);
    }

    /** Упавший прогон с записанной ошибкой. */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => CrawlerRunStatus::Failed,
            'attempts' => 1,
            'last_error' => 'boom',
            'last_error_at' => now(),
        ]);
    }

    /** Оборванный прогон: статус Running, но heartbeat устарел — кандидат на возобновление. */
    public function orphaned(): static
    {
        return $this->state(fn (): array => [
            'status' => CrawlerRunStatus::Running,
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subHour(),
        ]);
    }
}
