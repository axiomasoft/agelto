<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\StatusMappingApplicability;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Procurement\Acquisition\SnapshotFinalizer;
use App\Services\Procurement\StatusMappingService;
use Tests\Support\Postgres\ConcurrentTransactions;
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

test('точный реестр переопределяет platform default', function (): void {
    $platform = Platform::factory()->create();
    $registry = Registry::factory()->for($platform)->create();
    StatusMapping::factory()->forPlatform($platform)->create([
        'source_stage' => 'На проверке',
        'canonical_status' => ProcurementStatus::Evaluation->value,
    ]);
    StatusMapping::factory()->for($registry)->create([
        'source_stage' => 'На проверке',
        'canonical_status' => ProcurementStatus::Bidding->value,
    ]);

    $resolution = app(StatusMappingService::class)->resolve(registryId: $registry->id, sourceStage: 'На проверке');

    expect($resolution->status)->toBe(ProcurementStatus::Bidding)
        ->and($resolution->matchedApplicability)->toBe(StatusMappingApplicability::Registry)
        ->and($resolution->isNewlyMapped)->toBeFalse();
});

test('platform default применяется только к реестру этой площадки', function (): void {
    $platform = Platform::factory()->create();
    $registry = Registry::factory()->for($platform)->create();
    $otherRegistry = Registry::factory()->for(Platform::factory())->create();
    StatusMapping::factory()->forPlatform($platform)->create([
        'source_stage' => 'На проверке',
        'canonical_status' => ProcurementStatus::Evaluation->value,
    ]);

    $resolution = app(StatusMappingService::class)->resolve(registryId: $registry->id, sourceStage: 'На проверке');
    $otherResolution = app(StatusMappingService::class)->resolve(registryId: $otherRegistry->id, sourceStage: 'На проверке');

    expect($resolution->status)->toBe(ProcurementStatus::Evaluation)
        ->and($resolution->matchedApplicability)->toBe(StatusMappingApplicability::Platform)
        ->and($otherResolution->status)->toBe(ProcurementStatus::Unknown)
        ->and($otherResolution->matchedApplicability)->toBe(StatusMappingApplicability::Registry);
});

test('конкурентное обнаружение создаёт один exact unknown mapping', function (): void {
    $fixture = ConcurrentTransactions::execute(<<<'PHP'
$registry = \App\Models\Procurement\Platform\Registry::factory()->create();

return ['registry_id' => $registry->id, 'platform_id' => $registry->platform_id];
PHP);
    $registryId = $fixture['registry_id'];

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => statusMappingUnknownWorker(registryId: $registryId),
            'second' => statusMappingUnknownWorker(registryId: $registryId),
        ]);

        $state = ConcurrentTransactions::execute(<<<PHP
return ['count' => \App\Models\Procurement\Platform\StatusMapping::query()
    ->where('registry_id', {$registryId})
    ->where('source_stage', 'Новый concurrent этап')
    ->count()];
PHP);

        expect($workers['first']['status'])->toBe(ProcurementStatus::Unknown->value)
            ->and($workers['second']['status'])->toBe(ProcurementStatus::Unknown->value)
            ->and($state['count'])->toBe(1);
    } finally {
        ConcurrentTransactions::execute(<<<PHP
\App\Models\Procurement\Platform\StatusMapping::query()->where('registry_id', {$registryId})->delete();
\App\Models\Procurement\Platform\Registry::query()->whereKey({$registryId})->delete();
\App\Models\Procurement\Platform\Platform::query()->whereKey({$fixture['platform_id']})->delete();

return ['cleaned' => true];
PHP);
    }
});

test('обнаружение закупки с незнакомым статусом пишет одно canonical initial-status событие', function () {
    $registry = Registry::factory()->eis()->create();

    $procurement = app(SnapshotFinalizer::class)->ingest(SnapshotFactory::make($registry, [
        'sourceStage' => 'Совсем незнакомый статус',
    ]));

    $events = ProcurementEvent::query()
        ->where('procurement_id', $procurement->id)
        ->get();
    $statusEvents = $events->where('event_type', ProcurementEventType::StatusChanged);

    expect($events->pluck('event_type'))->toContain(ProcurementEventType::Discovered)
        ->and($statusEvents)->toHaveCount(1)
        ->and($statusEvents->sole()->old_value)->toBeNull()
        ->and($statusEvents->sole()->new_value)->toBe(ProcurementStatus::Unknown->value);
});

function statusMappingUnknownWorker(int $registryId): string
{
    return <<<PHP
\$resolution = app(\App\Services\Procurement\StatusMappingService::class)->resolve(
    registryId: {$registryId},
    sourceStage: 'Новый concurrent этап',
);

return [
    'status' => \$resolution->status->value,
    'new' => \$resolution->isNewlyMapped,
];
PHP;
}
