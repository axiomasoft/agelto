<?php

declare(strict_types=1);

namespace Database\Factories\Organization;

use App\Enums\Organization\ProcurementPartyRole;
use App\Models\Organization\Organization;
use App\Models\Organization\ProcurementParty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcurementParty>
 */
final class ProcurementPartyFactory extends Factory
{
    protected $model = ProcurementParty::class;

    public function definition(): array
    {
        return [
            // prc_procurements ещё нет — держим просто числовой id без FK.
            'procurement_id' => fake()->unique()->numberBetween(int1: 1, int2: 1_000_000),
            'organization_id' => Organization::factory(),
            'role' => fake()->randomElement(ProcurementPartyRole::cases()),
            'first_seen_revision_id' => null,
            'attributes' => null,
        ];
    }
}
