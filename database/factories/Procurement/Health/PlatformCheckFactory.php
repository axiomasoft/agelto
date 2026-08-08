<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Health;

use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Models\Procurement\Health\PlatformCheck;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlatformCheck> */
final class PlatformCheckFactory extends Factory
{
    protected $model = PlatformCheck::class;

    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'check_kind' => PlatformCheckKind::ListingFilter,
            'verdict' => PlatformCheckVerdict::Ok,
            'measured' => [],
            'baseline_sha256' => fake()->sha256(),
            'window_from' => now()->subDay(),
            'window_to' => now(),
            'sample_size' => 1,
            'requests_used' => 0,
            'message' => 'Пороги не калиброваны.',
            'checked_at' => now(),
        ];
    }
}
