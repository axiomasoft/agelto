<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Enums\Procurement\OperationLogLevel;
use App\Models\Procurement\OperationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationLog>
 */
final class OperationLogFactory extends Factory
{
    protected $model = OperationLog::class;

    public function definition(): array
    {
        return [
            'level' => OperationLogLevel::Info,
            'stage' => fake()->word(),
            'source' => null,
            'entity_type' => null,
            'entity_id' => null,
            'message' => fake()->sentence(),
            'context' => null,
            'trace' => null,
            'created_at' => now(),
        ];
    }
}
