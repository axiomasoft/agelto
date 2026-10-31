<?php

declare(strict_types=1);

use App\Models\Organization\Organization;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Organization\ProcurementPartyStoreRepository;
use App\Services\Procurement\Acquisition\SnapshotFinalizer;
use Tests\Support\Procurement\SnapshotFactory;

/**
 * D24/D28: detail-слой ставит заказчика, листинг не обязан его затирать на новой ревизии.
 */
test('заказчик, проставленный detail-слоем, переживает новую ревизию листинга', function () {
    $registry = Registry::factory()->eis()->create();
    $organization = Organization::factory()->create();
    $processor = app(SnapshotFinalizer::class);

    $procurement = $processor->ingest(SnapshotFactory::make($registry, [
        'title' => 'Поставка бумаги офисной',
    ]));

    app(ProcurementPartyStoreRepository::class)->setCustomerOrg($procurement, $organization->id);

    $updated = $processor->ingest(SnapshotFactory::make($registry, [
        'title' => 'Поставка бумаги офисной (уточнено)',
    ]));

    expect($updated->id)->toBe($procurement->id)
        ->and($updated->refresh()->customer_org_id)->toBe($organization->id);
});
