<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Ai;

use App\Enums\Procurement\FixtureClass;
use App\Enums\Procurement\GoldSource;
use App\Models\Procurement\Ai\Fixture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fixture>
 */
final class FixtureFactory extends Factory
{
    protected $model = Fixture::class;

    public function definition(): array
    {
        $code = 'EST-XLSX-KS2-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT);

        return [
            'code' => $code,
            'class' => FixtureClass::Estimate,
            'source_format' => 'xlsx',
            'form' => 'KS2',
            'storage_path' => Fixture::storagePathFor($code, 'xlsx'),
            'sha256' => hash('sha256', $code),
            'size_bytes' => $this->faker->numberBetween(1_000, 500_000),
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'original_filename' => 'source.xlsx',
            'source_path' => 'corpus/'.$code.'.xlsx',
            'generator' => null,
            'cluster_id' => 'CL-01',
            'doc_type' => null,
            'quirks' => [],
            'expected_estimates' => 1,
            'expected_rows' => null,
            'gold_source' => GoldSource::Deterministic,
            'is_verified' => false,
            'verified_by' => null,
            'verified_at' => null,
            'notes' => null,
        ];
    }

    public function est(): self
    {
        return $this->state(fn (): array => [
            'class' => FixtureClass::Estimate,
            'code' => 'EST-XLSX-KS2-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
        ]);
    }

    public function neg(): self
    {
        $code = 'NEG-XLSX-OTHER-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT);

        return $this->state(fn (): array => [
            'class' => FixtureClass::Negative,
            'code' => $code,
            'storage_path' => Fixture::storagePathFor($code, 'xlsx'),
            'gold_source' => GoldSource::None,
            'expected_estimates' => null,
        ]);
    }

    public function verified(): self
    {
        return $this->state(fn (): array => [
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }
}
