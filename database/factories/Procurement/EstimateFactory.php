<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\Analysis;
use App\Models\Procurement\DocumentVersion;
use App\Models\Procurement\Estimate;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Estimate>
 */
final class EstimateFactory extends Factory
{
    protected $model = Estimate::class;

    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'document_version_id' => DocumentVersion::factory(),
            'procurement_id' => Procurement::factory(),
            'title' => $this->faker->sentence(),
            'estimate_number' => $this->faker->bothify('##-СМ-####'),
            'total_amount' => $this->faker->randomFloat(2, 10_000, 5_000_000),
            'currency' => 'RUB',
            'base_year' => $this->faker->numberBetween(2000, 2001),
            'price_level' => 'ФЕР-2001',
            'passport' => null,
            'raw_result' => null,
            'raw_result_ref' => null,
            'rows_expected' => null,
            'rows_extracted' => null,
            'validation_status' => 'pending',
        ];
    }
}
