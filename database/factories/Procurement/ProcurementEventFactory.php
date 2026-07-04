<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcurementEvent>
 */
final class ProcurementEventFactory extends Factory
{
    protected $model = ProcurementEvent::class;

    public function definition(): array
    {
        return [
            'procurement_id' => Procurement::factory(),
            'revision_id' => null,
            'event_type' => ProcurementEventType::Discovered,
            'entity_ref' => null,
            'field' => null,
            'old_value' => null,
            'new_value' => null,
            'payload' => null,
            'occurred_at' => now(),
        ];
    }
}
