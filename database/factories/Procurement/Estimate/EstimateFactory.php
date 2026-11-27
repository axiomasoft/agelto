<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Estimate;

use App\Enums\Procurement\Estimate\CalcMethod;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Procurement;
use App\Models\User;
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
            'platform_id' => Platform::factory(),
            'estimate_number' => $this->faker->bothify('##-СМ-####'),
            'total_amount' => $this->faker->randomFloat(2, 10_000, 5_000_000),
            'currency' => 'RUB',
            'base_year' => $this->faker->numberBetween(2000, 2001),
            'price_level' => 'ФЕР-2001',
            'regulatory_base' => 'Приказ Минстроя России от 30.12.2021 № 1046/пр',
            'calc_method' => CalcMethod::BaseIndex,
            'labor_hours_workers' => $this->faker->randomFloat(6, 10, 1_000),
            'labor_hours_machinists' => $this->faker->randomFloat(6, 5, 500),
            'document_number' => $this->faker->bothify('АКТ-####'),
            'document_date' => $this->faker->date(),
            'period_start' => $this->faker->date(),
            'period_end' => $this->faker->date(),
            'passport' => null,
            'raw_result' => null,
            'raw_result_ref' => null,
            'raw_result_disk' => null,
            'rows_expected' => null,
            'rows_extracted' => null,
            'validation_status' => 'pending',
            // Ось «оператор проверил боевой разбор» — пусто по умолчанию, это факт человека,
            // а не разбора; проверенная смета — state verified() ниже.
            'verified_at' => null,
            'verified_by' => null,
            'source_sheet_name' => null,
            'source_row_start' => null,
            'source_row_end' => null,
            'source_page_start' => null,
            'source_page_end' => null,
        ];
    }

    /**
     * Смета, проверенная оператором: `verified_at`/`verified_by` заполнены вместе.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verified_at' => now(),
            'verified_by' => User::factory(),
        ]);
    }

    /**
     * Историческая запись с материализованным диском сырого результата — по
     * умолчанию `raw_result_disk` пуст.
     */
    public function onDisk(string $disk): static
    {
        return $this->state(fn (): array => ['raw_result_disk' => $disk]);
    }
}
