<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\Clarification;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Clarification>
 */
final class ClarificationFactory extends Factory
{
    protected $model = Clarification::class;

    public function definition(): array
    {
        return [
            'procurement_id' => Procurement::factory(),
            'requested_at' => now()->toDateString(),
            'answered_at' => null,
            'question' => fake()->sentence(),
            'answer' => null,
            'document_version_id' => null,
        ];
    }
}
