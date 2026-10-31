<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcurementRevision>
 */
final class ProcurementRevisionFactory extends Factory
{
    protected $model = ProcurementRevision::class;

    public function definition(): array
    {
        $snapshot = ['title' => fake()->sentence()];

        return [
            'procurement_id' => Procurement::factory(),
            'revision_number' => 1,
            'source_edition' => null,
            'change_reason' => null,
            'snapshot' => $snapshot,
            'snapshot_hash' => hash(algo: 'sha256', data: json_encode($snapshot)),
            'source_updated_at' => now(),
            'detected_at' => now(),
            'process_run_id' => null,
        ];
    }
}
