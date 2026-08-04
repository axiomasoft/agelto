<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Ai;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\FixtureClass;
use App\Enums\Procurement\Ai\GoldSource;
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
            'source_format' => 'xlsx',
            'storage_path' => Fixture::storagePathFor($code, 'xlsx'),
            'disk' => null,
            'sha256' => hash('sha256', $code),
            'size_bytes' => $this->faker->numberBetween(1_000, 500_000),
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'original_filename' => 'source.xlsx',
            'source_path' => 'corpus/'.$code.'.xlsx',
            'generator' => null,
            'cluster_id' => 'CL-01',
            'document_type' => AiDocumentType::Estimate,
            'owner_document_type' => AiDocumentType::Estimate,
            'title' => "Эталон {$code}",
            'quirks' => [],
            'type_profile' => [
                'class' => FixtureClass::Estimate->value,
                'form' => 'KS2',
                'expected_estimates' => 1,
                'expected_rows' => null,
                'gold_source' => GoldSource::Deterministic->value,
            ],
            'is_verified' => false,
            'verified_by' => null,
            'verified_at' => null,
            'notes' => null,
            'document_id' => null,
        ];
    }

    public function est(): self
    {
        $code = 'EST-XLSX-KS2-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT);

        return $this->state(fn (array $attributes): array => [
            'code' => $code,
            'document_type' => AiDocumentType::Estimate,
            'owner_document_type' => AiDocumentType::Estimate,
            'title' => "Эталон {$code}",
            'type_profile' => array_merge((array) ($attributes['type_profile'] ?? []), [
                'class' => FixtureClass::Estimate->value,
            ]),
        ]);
    }

    public function neg(): self
    {
        $code = 'NEG-XLSX-OTHER-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT);

        return $this->state(fn (array $attributes): array => [
            'code' => $code,
            'storage_path' => Fixture::storagePathFor($code, 'xlsx'),
            'document_type' => AiDocumentType::TechSpec,
            'owner_document_type' => AiDocumentType::Estimate,
            'title' => "Эталон {$code}",
            'type_profile' => array_merge((array) ($attributes['type_profile'] ?? []), [
                'class' => FixtureClass::Negative->value,
                'gold_source' => GoldSource::None->value,
                'expected_estimates' => null,
            ]),
        ]);
    }

    public function verified(): self
    {
        return $this->state(fn (): array => [
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }

    /**
     * Историческая запись с материализованным диском (P6.2/P6.5) — по умолчанию `disk` пуст.
     */
    public function onDisk(string $disk): self
    {
        return $this->state(fn (): array => ['disk' => $disk]);
    }
}
