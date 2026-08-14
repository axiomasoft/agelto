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
            'registry_id' => fn (): int|string => $this->eisRegistry()->getKey(),
            'registry_number' => fake()->unique()->numerify('0000000000##########'),
            'etp_number' => null,
            'ikz' => null,
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
            'detail_fetched_at' => null,
            'detail_attempts' => 0,
            'detail_last_error' => null,
            'detail_next_retry_at' => null,
            'detail_failed' => false,
            'registry_guid' => null,
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
