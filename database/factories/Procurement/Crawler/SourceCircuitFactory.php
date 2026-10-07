<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

use App\Enums\Procurement\Crawler\SourceStage;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SourceCircuit> */
final class SourceCircuitFactory extends Factory
{
    protected $model = SourceCircuit::class;

    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'stage' => SourceStage::Listing,
            'enabled' => true,
            'manually_paused' => false,
            'consecutive_failures' => 0,
        ];
    }
}
