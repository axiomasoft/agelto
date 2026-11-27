<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Enums\Procurement\AwardStatus;
use App\Models\Procurement\Award;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Award>
 */
final class AwardFactory extends Factory
{
    protected $model = Award::class;

    public function definition(): array
    {
        return [
            'procurement_id' => Procurement::factory(),
            'lot_id' => null,
            'winner_org_id' => null,
            'final_price' => fake()->randomFloat(nbMaxDecimals: 2, min: 10000, max: 1000000),
            'currency' => 'RUB',
            'status' => AwardStatus::Awarded,
            'contract_registry_number' => null,
            'awarded_at' => now(),
            'payload' => null,
        ];
    }
}
