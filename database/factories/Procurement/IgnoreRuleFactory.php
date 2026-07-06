<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\IgnoreRule;
use App\Models\Procurement\Registry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IgnoreRule>
 */
final class IgnoreRuleFactory extends Factory
{
    protected $model = IgnoreRule::class;

    public function definition(): array
    {
        return [
            'registry_id' => Registry::factory(),
            'field' => 'title',
            'operator' => 'contains',
            'value' => fake()->word(),
            'reason_category' => 'test_purchase',
            'is_active' => true,
        ];
    }
}
