<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use Illuminate\Database\Seeder;

/**
 * Стартовая матрица `prc_status_mappings` для реестра `fz44` площадки `eis`:
 * пять пар «этап ЕИС → канонический статус». Метки сохраняются дословно:
 * натуральный ключ матрицы сравнивается без нормализации.
 *
 * Матрица открыта: `StatusMappingService::resolve()` создаёт `unknown` для незнакомой пары.
 * Начальные значения нужны и в production, поэтому входят в базовый набор сидов до
 * local/testing-гейта демонстрационных данных.
 *
 * Идемпотентен: `updateOrCreate` по `(registry_id, source_stage)`. Если реестр ещё
 * не установлен, выводит предупреждение; сид можно повторить после установки реестра.
 */
final class StatusMappingSeeder extends Seeder
{
    public function run(): void
    {
        $registries = Registry::query()
            ->where('code', 'fz44')
            ->whereHas('platform', fn ($query) => $query->where('code', 'eis'))
            ->get();

        if ($registries->isEmpty()) {
            $this->command->warn('StatusMappingSeeder: реестров с code=fz44 не найдено — маппинги не заведены, повторить сид после создания реестра.');

            return;
        }

        foreach ($registries as $registry) {
            foreach ($this->mappings() as $mapping) {
                StatusMapping::query()->updateOrCreate(
                    attributes: [
                        'registry_id' => $registry->id,
                        'source_stage' => $mapping['source_stage'],
                    ],
                    values: [
                        'canonical_status' => $mapping['canonical_status'],
                    ],
                );
            }
        }
    }

    /**
     * @return list<array{source_stage: string, canonical_status: string}>
     */
    private function mappings(): array
    {
        return [
            ['source_stage' => 'Подача заявок', 'canonical_status' => ProcurementStatus::Bidding->value],
            ['source_stage' => 'Работа комиссии', 'canonical_status' => ProcurementStatus::Evaluation->value],
            ['source_stage' => 'Закупка завершена', 'canonical_status' => ProcurementStatus::Completed->value],
            ['source_stage' => 'Закупка отменена', 'canonical_status' => ProcurementStatus::Cancelled->value],
            ['source_stage' => 'Определение поставщика отменено', 'canonical_status' => ProcurementStatus::Cancelled->value],
        ];
    }
}
