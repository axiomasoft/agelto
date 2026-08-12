<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use Illuminate\Database\Seeder;

/**
 * Стартовая матрица `prc_status_mappings` для реестра `fz44` (DOMLANG D17,
 * `findings/P2-eis-stages.md` §1) — 5 доказанных пар «этап ЕИС → канонический статус».
 *
 * Пара «Определение поставщика отменено» → `cancelled` снята 2026-08-07 в P1.4:
 * `plans/archive/2026.08.06-CRAWL-HARDEN/artifacts/eis-stages.json` (`stages[3]`). Строка копируется
 * дословно: натуральный ключ матрицы сравнивается без нормализации.
 *
 * НЕ закрытый codelist: `StatusMappingService::resolve()` на незнакомой паре по-прежнему
 * заводит `unknown` через `firstOrCreate` (V2-CORE D5) — сид делает эту тропу редкой, а не
 * отменяет её. Инфраструктурные данные нужны и в prod, поэтому вызывается в `DatabaseSeeder`
 * ДО local/testing-гейта, как `AiRoutingSeeder`/`PromptSeeder`.
 *
 * Идемпотентен: `updateOrCreate` по натуральному ключу `(registry_id, source_stage)` — тот же
 * `unique`-индекс, что в миграции `2026_07_04_100002`. Реестров `fz44` нет → no-op + `warn`,
 * не исключение (площадка может быть заведена позже — Q7, `open-questions.md`).
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
