<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Procurement\ProcurementIngestProcessor;
use App\Services\Procurement\StatusMappingService;
use Tests\Support\Procurement\SnapshotFactory;

/**
 * §11 «Неизвестный статус 223-ФЗ» — резолв в `unknown` + алерт-событие,
 * оператор дополняет `prc_status_mappings`.
 */
test('незнакомый сырой статус резолвится в unknown и заводит строку маппинга для оператора', function () {
    $registry = Registry::factory()->create();

    $resolution = app(StatusMappingService::class)->resolve(registryId: $registry->id, sourceStage: 'Совершенно новый статус площадки');

    expect($resolution->status)->toBe(ProcurementStatus::Unknown)
        ->and($resolution->isNewlyMapped)->toBeTrue();

    $mapping = StatusMapping::query()
        ->where('registry_id', $registry->id)
        ->where('source_stage', 'Совершенно новый статус площадки')
        ->sole();

    expect($mapping->canonical_status)->toBe(ProcurementStatus::Unknown->value);
});

test('повторный резолв уже заведённого статуса не считается новым маппингом', function () {
    $registry = Registry::factory()->create();
    $service = app(StatusMappingService::class);

    $first = $service->resolve(registryId: $registry->id, sourceStage: 'Экзотика');
    $second = $service->resolve(registryId: $registry->id, sourceStage: 'Экзотика');

    expect($first->isNewlyMapped)->toBeTrue()
        ->and($second->isNewlyMapped)->toBeFalse()
        ->and(StatusMapping::query()->where('registry_id', $registry->id)->where('source_stage', 'Экзотика')->count())->toBe(1);
});

test('обнаружение закупки с незнакомым статусом пишет событие status_changed рядом с discovered', function () {
    $registry = Registry::factory()->create();

    $procurement = app(ProcurementIngestProcessor::class)->ingest(SnapshotFactory::make($registry, [
        'sourceStage' => 'Совсем незнакомый статус',
    ]));

    $eventTypes = ProcurementEvent::query()
        ->where('procurement_id', $procurement->id)
        ->pluck('event_type');

    expect($eventTypes)->toContain(ProcurementEventType::Discovered)
        ->and($eventTypes)->toContain(ProcurementEventType::StatusChanged);
});
