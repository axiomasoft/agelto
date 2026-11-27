<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Models\Procurement\Crawler\Crawler;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Crawler> */
final class CrawlerFactory extends Factory
{
    protected $model = Crawler::class;

    /** A neutral profile requires the caller's registered registry and typed filter. */
    public function definition(): array
    {
        return ['name' => fake()->words(asText: true), 'listing_kind' => AcquisitionKind::Fresh,
            'is_active' => true, 'schedule' => null, 'profile_version' => 1, 'route_profile_id' => null];
    }
}
