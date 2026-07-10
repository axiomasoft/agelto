<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\ProcurementStoreRepository;
use Illuminate\Support\Carbon;
use Tests\Support\Procurement\SnapshotFactory;

/**
 * DOMLANG P3.3 (D13) — `etp_number` имеет write-path DTO→репозиторий→колонка, но пока ни один
 * источник его не заполняет (223-ФЗ forward-provision). Пин защищает от тихого промаха будущего
 * продюсера мимо колонки.
 */
test('etp_number сохраняется через storeProcurement, когда передан снапшотом', function () {
    $registry = Registry::factory()->create();

    $procurement = app(ProcurementStoreRepository::class)->storeProcurement(
        data: SnapshotFactory::make($registry, ['etpNumber' => 'ETP-000123']),
        status: ProcurementStatus::Bidding->value,
        seenAt: Carbon::now(),
    );

    expect($procurement->fresh()->etp_number)->toBe('ETP-000123');
});

test('etp_number остаётся null по умолчанию и не роняет запись', function () {
    $registry = Registry::factory()->create();

    $procurement = app(ProcurementStoreRepository::class)->storeProcurement(
        data: SnapshotFactory::make($registry, ['registryNumber' => '0173100000123000002']),
        status: ProcurementStatus::Bidding->value,
        seenAt: Carbon::now(),
    );

    expect($procurement->fresh()->etp_number)->toBeNull();
});
