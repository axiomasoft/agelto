<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementEventType;
use Tests\Support\Postgres\ConcurrentTransactions;

test('два PostgreSQL worker с одинаковым снимком создают одну логическую ревизию', function () {
    $fixture = concurrentIngestFixture();

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => concurrentIngestWorker(registryId: $fixture['registry_id'], initialPrice: '150000.00'),
            'second' => concurrentIngestWorker(registryId: $fixture['registry_id'], initialPrice: '150000.00'),
        ]);

        $state = concurrentIngestState(registryId: $fixture['registry_id']);

        expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            ->and($state['revision_numbers'])->toBe([1])
            ->and($state['revision_hashes'])->toHaveCount(1)
            ->and($state['revision_count'])->toBe(1)
            ->and($state['current_revision_id'])->toBe($state['revision_ids'][0])
            ->and($state['events'])->toBe([ProcurementEventType::Discovered->value]);
    } finally {
        concurrentIngestCleanup(registryId: $fixture['registry_id']);
    }
});

test('два PostgreSQL worker с разными снимками создают монотонную историю и canonical head', function () {
    $fixture = concurrentIngestFixture();

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => concurrentIngestWorker(registryId: $fixture['registry_id'], initialPrice: '150000.00'),
            'second' => concurrentIngestWorker(registryId: $fixture['registry_id'], initialPrice: '175000.00'),
        ]);

        $state = concurrentIngestState(registryId: $fixture['registry_id']);

        expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            ->and($state['revision_numbers'])->toBe([1, 2])
            ->and($state['revision_hashes'])->toHaveCount(2)
            ->and($state['revision_hashes'][0])->not->toBe($state['revision_hashes'][1])
            ->and($state['revision_count'])->toBe(2)
            ->and($state['current_revision_id'])->toBe($state['revision_ids'][1])
            ->and($state['events'])->toBe([
                ProcurementEventType::Discovered->value,
                ProcurementEventType::RevisionPublished->value,
                ProcurementEventType::PriceChanged->value,
            ]);
    } finally {
        concurrentIngestCleanup(registryId: $fixture['registry_id']);
    }
});

/**
 * @return array{registry_id: int}
 */
function concurrentIngestFixture(): array
{
    return ConcurrentTransactions::execute(<<<'PHP'
$registry = \App\Models\Procurement\Platform\Registry::factory()->create();
\App\Models\Procurement\Platform\StatusMapping::factory()->create([
    'registry_id' => $registry->id,
    'source_stage' => 'Подача заявок',
    'canonical_status' => 'bidding',
]);

return ['registry_id' => $registry->id];
PHP);
}

function concurrentIngestWorker(int $registryId, string $initialPrice): string
{
    return <<<PHP
\$registry = \App\Models\Procurement\Platform\Registry::query()->findOrFail({$registryId});
\$snapshot = new \App\Dto\Procurement\ProcurementSnapshotData(
    registryId: \$registry->id,
    registryNumber: '0173100000123000001',
    title: 'Поставка бумаги офисной',
    sourceStage: 'Подача заявок',
    publishedAt: \Illuminate\Support\Carbon::parse('2026-01-10 10:00:00'),
    sourceUpdatedAt: \Illuminate\Support\Carbon::parse('2026-01-10 10:00:00'),
    initialPrice: '{$initialPrice}',
);
\$procurement = app(\App\Services\Procurement\ProcurementIngestProcessor::class)->ingest(data: \$snapshot);

return [
    'procurement_id' => \$procurement->id,
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()'),
];
PHP;
}

/**
 * @return array{revision_numbers: list<int>, revision_hashes: list<string>, revision_ids: list<int>, revision_count: int, current_revision_id: int, events: list<string>}
 */
function concurrentIngestState(int $registryId): array
{
    return ConcurrentTransactions::execute(<<<PHP
\$procurement = \App\Models\Procurement\Procurement::query()
    ->where('registry_id', {$registryId})
    ->where('registry_number', '0173100000123000001')
    ->sole();
\$revisions = \App\Models\Procurement\ProcurementRevision::query()
    ->where('procurement_id', \$procurement->id)
    ->orderBy('revision_number')
    ->get();

return [
    'revision_numbers' => \$revisions->pluck('revision_number')->all(),
    'revision_hashes' => \$revisions->pluck('snapshot_hash')->all(),
    'revision_ids' => \$revisions->pluck('id')->all(),
    'revision_count' => \$procurement->revision_count,
    'current_revision_id' => \$procurement->current_revision_id,
    'events' => \App\Models\Procurement\ProcurementEvent::query()
        ->where('procurement_id', \$procurement->id)
        ->orderBy('id')
        ->pluck('event_type')
        ->all(),
];
PHP);
}

function concurrentIngestCleanup(int $registryId): void
{
    ConcurrentTransactions::execute(<<<PHP
\$procurements = \App\Models\Procurement\Procurement::query()->where('registry_id', {$registryId})->get();

foreach (\$procurements as \$procurement) {
    \App\Models\Procurement\ProcurementEvent::query()->where('procurement_id', \$procurement->id)->delete();
    \App\Models\Procurement\ProcurementRevision::query()->where('procurement_id', \$procurement->id)->delete();
    \$procurement->delete();
}

\App\Models\Procurement\Platform\StatusMapping::query()->where('registry_id', {$registryId})->delete();
\App\Models\Procurement\Platform\Registry::query()->whereKey({$registryId})->delete();

return ['cleaned' => true];
PHP);
}
