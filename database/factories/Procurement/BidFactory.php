<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Enums\Procurement\BidStatus;
use App\Models\Procurement\Bid;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bid>
 */
final class BidFactory extends Factory
{
    protected $model = Bid::class;

    public function definition(): array
    {
        return [
            'procurement_id' => Procurement::factory(),
            'lot_id' => null,
            'protocol_id' => null,
            'organization_id' => null,
            'bid_number' => fake()->numerify('###'),
            'participant_ordinal' => fake()->numberBetween(int1: 1, int2: 10),
            'price' => fake()->randomFloat(nbMaxDecimals: 2, min: 10000, max: 1000000),
            'status' => BidStatus::Admitted,
            'reject_reason' => null,
            'final_rank' => null,
        ];
    }
}
