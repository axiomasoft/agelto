<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Services\Procurement\StatusMappingService;
use Database\Seeders\StatusMappingSeeder;

/**
 * DOMLANG P2.2 (D17) — стартовая матрица `prc_status_mappings` для реестра fz44.
 */
test('сид заводит ровно 4 маппинга для реестра fz44', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);

    $this->seed(StatusMappingSeeder::class);

    $mappings = StatusMapping::query()
        ->where('registry_id', $registry->id)
        ->pluck('canonical_status', 'source_status');

    expect($mappings)->toHaveCount(4)
        ->and($mappings['Подача заявок'])->toBe(ProcurementStatus::Bidding->value)
        ->and($mappings['Работа комиссии'])->toBe(ProcurementStatus::Evaluation->value)
        ->and($mappings['Закупка завершена'])->toBe(ProcurementStatus::Completed->value)
        ->and($mappings['Закупка отменена'])->toBe(ProcurementStatus::Cancelled->value);
});

test('повторный прогон сидера идемпотентен — число строк не меняется', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);

    $this->seed(StatusMappingSeeder::class);
    $this->seed(StatusMappingSeeder::class);

    expect(StatusMapping::query()->where('registry_id', $registry->id)->count())->toBe(4);
});

test('после сида резолв Работа комиссии даёт Evaluation и не считается новым маппингом', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $this->seed(StatusMappingSeeder::class);

    $resolution = app(StatusMappingService::class)->resolve(registryId: $registry->id, sourceStatus: 'Работа комиссии');

    expect($resolution->status)->toBe(ProcurementStatus::Evaluation)
        ->and($resolution->isNewlyMapped)->toBeFalse();
});

test('после сида незнакомое значение по-прежнему резолвится в unknown как новый маппинг', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $this->seed(StatusMappingSeeder::class);

    $resolution = app(StatusMappingService::class)->resolve(registryId: $registry->id, sourceStatus: 'Пятое значение');

    expect($resolution->status)->toBe(ProcurementStatus::Unknown)
        ->and($resolution->isNewlyMapped)->toBeTrue();
});

test('без реестров fz44 сид не падает и не пишет строк', function () {
    Registry::factory()->create(['code' => 'fz223']);

    $this->seed(StatusMappingSeeder::class);

    expect(StatusMapping::query()->count())->toBe(0);
});
