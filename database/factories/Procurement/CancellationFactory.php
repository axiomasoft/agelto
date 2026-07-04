<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\Cancellation;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cancellation>
 */
final class CancellationFactory extends Factory
{
    protected $model = Cancellation::class;

    public function definition(): array
    {
        return [
            'procurement_id' => Procurement::factory(),
            'decision_at' => now(),
            'reason' => fake()->sentence(),
            'document_version_id' => null,
            'reverted_at' => null,
        ];
    }
}
