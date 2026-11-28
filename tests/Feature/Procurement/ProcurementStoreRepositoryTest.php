<?php

declare(strict_types=1);

use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Acquisition\Detail\SnapshotFinalizer;
use Tests\Support\Procurement\SnapshotFactory;

/**
 * DOMLANG `etp_number` имеет write-path DTO→репозиторий→колонка, но пока ни один
 * источник его не заполняет (223-ФЗ forward-provision). Пин защищает от тихого промаха будущего
 * продюсера мимо колонки.
 */
test('etp_number сохраняется через storeProcurement, когда передан снапшотом', function () {
    $registry = Registry::factory()->eis()->create();

    $procurement = app(SnapshotFinalizer::class)->ingest(
        data: SnapshotFactory::make($registry, ['etpNumber' => 'ETP-000123']),
    );

    expect(EisDetails::query()->find($procurement->id)?->etp_number)->toBe('ETP-000123');
});

test('etp_number остаётся null по умолчанию и не роняет запись', function () {
    $registry = Registry::factory()->eis()->create();

    $procurement = app(SnapshotFinalizer::class)->ingest(
        data: SnapshotFactory::make($registry, ['registryNumber' => '0173100000123000002']),
    );

    expect(EisDetails::query()->find($procurement->id)?->etp_number)->toBeNull();
});
