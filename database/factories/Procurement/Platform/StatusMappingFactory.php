<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Platform;

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusMapping>
 */
final class StatusMappingFactory extends Factory
{
    protected $model = StatusMapping::class;

    public function definition(): array
    {
        return [
            'registry_id' => Registry::factory(),
            'source_status' => fake()->unique()->word(),
            // Пул тянем из enum, а не из литералов: иначе удалённый кейс переживает рефакторинг
            // и всплывает как ValueError в StatusMappingService::resolve().
            'canonical_status' => fake()->randomElement(array_column(ProcurementStatus::cases(), 'value')),
        ];
    }
}
