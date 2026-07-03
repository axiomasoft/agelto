<?php

declare(strict_types=1);

namespace Database\Factories\Crawler;

use App\Enums\Crawling\CrawlingStatus;
use App\Models\Crawler\Link;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Link>
 */
final class LinkFactory extends Factory
{
    protected $model = Link::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'reg_number' => (string) fake()->unique()->numerify('#######'),
            'detail_url' => 'https://zakupki.gov.ru/order/'.fake()->unique()->numerify('#######'),
            'status' => CrawlingStatus::PENDING,
        ];
    }

    /** Ожидает разбора документов. */
    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => CrawlingStatus::PENDING]);
    }

    /** Документы в обработке. */
    public function processing(): static
    {
        return $this->state(fn (): array => ['status' => CrawlingStatus::PROCESSING]);
    }

    /** Все вложения разобраны. */
    public function completed(): static
    {
        return $this->state(fn (): array => ['status' => CrawlingStatus::COMPLETED]);
    }

    /** Разбор провалился. */
    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => CrawlingStatus::FAILED]);
    }
}
