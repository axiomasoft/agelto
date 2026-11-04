<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Services\Procurement\StatusMappingService;
use Database\Seeders\StatusMappingSeeder;

/**
 * DOMLANG стартовая матрица `prc_status_mappings` для реестра fz44.
 */
test('сид заводит ровно 5 маппингов для реестра fz44', function () {
    $registry = eisStatusMappingRegistry();

    $this->seed(StatusMappingSeeder::class);

    $mappings = StatusMapping::query()
        ->where('registry_id', $registry->id)
        ->pluck('canonical_status', 'source_stage');

    expect($mappings)->toHaveCount(5)
        ->and($mappings['Подача заявок'])->toBe(ProcurementStatus::Bidding->value)
        ->and($mappings['Работа комиссии'])->toBe(ProcurementStatus::Evaluation->value)
        ->and($mappings['Закупка завершена'])->toBe(ProcurementStatus::Completed->value)
        ->and($mappings['Закупка отменена'])->toBe(ProcurementStatus::Cancelled->value)
        ->and($mappings['Определение поставщика отменено'])->toBe(ProcurementStatus::Cancelled->value);
});

test('повторный прогон сидера идемпотентен — число строк не меняется', function () {
    $registry = eisStatusMappingRegistry();

    $this->seed(StatusMappingSeeder::class);
    $this->seed(StatusMappingSeeder::class);

    expect(StatusMapping::query()->where('registry_id', $registry->id)->count())->toBe(5);
});

test('сид покрывает снятый этап отмены дословной строкой площадки', function () {
    $registry = eisStatusMappingRegistry();

    $this->seed(StatusMappingSeeder::class);

    expect(StatusMapping::query()->where([
        'registry_id' => $registry->id,
        'source_stage' => 'Определение поставщика отменено',
    ])->value('canonical_status'))->toBe(ProcurementStatus::Cancelled->value);
});

test('после сида резолв Работа комиссии даёт Evaluation и не считается новым маппингом', function () {
    $registry = eisStatusMappingRegistry();
    $this->seed(StatusMappingSeeder::class);

    $resolution = app(StatusMappingService::class)->resolve(registryId: $registry->id, sourceStage: 'Работа комиссии');

    expect($resolution->status)->toBe(ProcurementStatus::Evaluation)
        ->and($resolution->isNewlyMapped)->toBeFalse();
});

test('после сида незнакомое значение по-прежнему резолвится в unknown как новый маппинг', function () {
    $registry = eisStatusMappingRegistry();
    $this->seed(StatusMappingSeeder::class);

    $resolution = app(StatusMappingService::class)->resolve(registryId: $registry->id, sourceStage: 'Пятое значение');

    expect($resolution->status)->toBe(ProcurementStatus::Unknown)
        ->and($resolution->isNewlyMapped)->toBeTrue();
});

test('без реестров fz44 сид не падает и не пишет строк для другого реестра', function () {
    $registry = Registry::factory()->create(['code' => 'fz223']);

    $this->seed(StatusMappingSeeder::class);

    expect(StatusMapping::query()->where('registry_id', $registry->id)->count())->toBe(0);
});

test('сид не выбирает fz44 другой платформы', function (): void {
    $eisRegistry = Registry::factory()->for(Platform::factory()->create(['code' => 'eis']))->create(['code' => 'fz44']);
    $otherRegistry = Registry::factory()->for(Platform::factory()->create(['code' => 'other']))->create(['code' => 'fz44']);

    $this->seed(StatusMappingSeeder::class);

    expect(StatusMapping::query()->where('registry_id', $eisRegistry->id)->count())->toBe(5)
        ->and(StatusMapping::query()->where('registry_id', $otherRegistry->id)->count())->toBe(0);
});

function eisStatusMappingRegistry(): Registry
{
    return Registry::factory()
        ->for(Platform::factory()->create(['code' => 'eis']))
        ->create(['code' => 'fz44']);
}
