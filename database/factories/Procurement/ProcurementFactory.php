<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Procurement>
 */
final class ProcurementFactory extends Factory
{
    protected $model = Procurement::class;

    public function definition(): array
    {
        return [
            'registry_id' => fn (): int => $this->integer($this->eisRegistry()->getKey()),
            'platform_id' => fn (array $attributes): int => $this->registry($attributes)->platform_id,
            'source_identity_type' => fn (array $attributes): string => $this->registry($attributes)->code === 'fz44' ? 'registry:44fz' : 'registry:'.$this->registry($attributes)->code,
            'source_identity_value' => fake()->unique()->numerify('0000000000##########'),
            'title' => fake()->sentence(),
            'status' => ProcurementStatus::Bidding,
            'source_stage' => 'Подача заявок',
            'procedure_type' => null,
            'procedure_type_raw' => null,
            'customer_org_id' => null,
            'initial_price' => fake()->randomFloat(nbMaxDecimals: 2, min: 10000, max: 1000000),
            'currency' => 'RUB',
            'advance_percent' => null,
            'okved_code' => null,
            'okved_additional' => null,
            'region_code' => null,
            'published_at' => now(),
            'source_updated_at' => now(),
            'bidding_starts_at' => null,
            'bidding_ends_at' => null,
            'summary_at' => null,
            'detail_url' => null,
            'current_revision_id' => null,
            'current_revision_detected_at' => null,
            'revision_count' => 1,
            'ignored_at' => null,
            'ignore_reason' => null,
            'attributes' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    private function integer(mixed $id): int
    {
        return is_int($id) ? $id : throw new \LogicException('Invalid factory registry ID.');
    }

    /** @param array<mixed> $attributes */
    private function registry(array $attributes): Registry
    {
        return Registry::query()->whereKey($this->integer($attributes['registry_id']))->firstOrFail();
    }

    private function eisRegistry(): Registry
    {
        $platform = Platform::query()->firstOrCreate(
            attributes: ['code' => 'eis'],
            values: [
                'name' => 'Единая информационная система',
                'base_url' => 'https://zakupki.gov.ru',
                'is_active' => true,
                'is_public' => true,
            ],
        );

        return Registry::query()->firstOrCreate(
            attributes: [
                'platform_id' => $platform->getKey(),
                'code' => 'fz44',
            ],
            values: [
                'name' => 'ЕИС 44-ФЗ',
                'settings' => ['records_per_page' => 50],
            ],
        );
    }
}
