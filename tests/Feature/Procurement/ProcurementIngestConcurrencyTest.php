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
            ->and($state['events'])->toBe([
                ProcurementEventType::Discovered->value,
                ProcurementEventType::StatusChanged->value,
            ]);
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
                ProcurementEventType::StatusChanged->value,
                ProcurementEventType::RevisionPublished->value,
                ProcurementEventType::PriceChanged->value,
            ]);
    } finally {
        concurrentIngestCleanup(registryId: $fixture['registry_id']);
    }
});

test('logical head следует номеру ревизии при обратном порядке времени обнаружения', function () {
    $fixture = concurrentIngestFixture();
    $lockReadyPath = concurrentIngestSignalPath(prefix: 'agelto-ingest-lock-');
    $waitingPidPath = concurrentIngestSignalPath(prefix: 'agelto-ingest-waiter-');

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => concurrentLockedFirstIngestWorker(
                registryId: $fixture['registry_id'],
                lockReadyPath: $lockReadyPath,
                waitingPidPath: $waitingPidPath,
            ),
            'second' => concurrentWaitingSecondIngestWorker(
                registryId: $fixture['registry_id'],
                lockReadyPath: $lockReadyPath,
                waitingPidPath: $waitingPidPath,
            ),
        ]);

        $third = ConcurrentTransactions::execute(concurrentIngestWorker(
            registryId: $fixture['registry_id'],
            initialPrice: '200000.00',
            detectedAt: '2026-01-10 10:00:02',
        ));
        $state = concurrentIngestState(registryId: $fixture['registry_id']);

        expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            ->and($workers['first']['waiting_backend_pid'])->toBe($workers['second']['backend_pid'])
            ->and($third['procurement_id'])->toBe($workers['first']['procurement_id'])
            ->and($state['revision_numbers'])->toBe([1, 2, 3])
            ->and($state['detected_at'])->toBe([
                '2026-01-10 10:00:01',
                '2026-01-10 10:00:00',
                '2026-01-10 10:00:02',
            ])
            ->and($state['revision_count'])->toBe(3)
            ->and($state['current_revision_number'])->toBe(3)
            ->and($state['current_revision_id'])->toBe($state['revision_ids'][2]);
    } finally {
        concurrentIngestRemoveSignal(path: $lockReadyPath);
        concurrentIngestRemoveSignal(path: $waitingPidPath);
        concurrentIngestCleanup(registryId: $fixture['registry_id']);
    }
});

/**
 * @return array{registry_id: int}
 */
function concurrentIngestFixture(): array
{
    return ConcurrentTransactions::execute(<<<'PHP'
$registry = \App\Models\Procurement\Platform\Registry::factory()->eis()->create();
\App\Models\Procurement\Platform\StatusMapping::factory()->create([
    'registry_id' => $registry->id,
    'source_stage' => 'Подача заявок',
    'canonical_status' => 'bidding',
]);

return ['registry_id' => $registry->id];
PHP);
}

function concurrentIngestWorker(int $registryId, string $initialPrice, ?string $detectedAt = null): string
{
    $setTestNow = $detectedAt === null
        ? ''
        : '\Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('.var_export($detectedAt, true).'));';

    return <<<PHP
{$setTestNow}
\$registry = \App\Models\Procurement\Platform\Registry::query()->findOrFail({$registryId});
\$snapshot = new \App\Dto\Procurement\ProcurementSnapshotData(
    registryId: \$registry->id,
    identity: app(\App\Services\Procurement\Acquisition\IdentityResolver::class)->forRegistry(\$registry->id, '0173100000123000001')->source,
    title: 'Поставка бумаги офисной',
    sourceStage: 'Подача заявок',
    publishedAt: \Illuminate\Support\Carbon::parse('2026-01-10 10:00:00'),
    sourceUpdatedAt: \Illuminate\Support\Carbon::parse('2026-01-10 10:00:00'),
    initialPrice: '{$initialPrice}',
);
\$procurement = app(\App\Services\Procurement\Acquisition\SnapshotFinalizer::class)->ingest(data: \$snapshot);

return [
    'procurement_id' => \$procurement->id,
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()'),
];
PHP;
}

function concurrentLockedFirstIngestWorker(int $registryId, string $lockReadyPath, string $waitingPidPath): string
{
    $lockReadyPath = var_export($lockReadyPath, true);
    $waitingPidPath = var_export($waitingPidPath, true);

    return <<<PHP
\Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-01-10 10:00:01'));
\$connection = \Illuminate\Support\Facades\DB::connection();
\$connection->beginTransaction();

try {
    \$registry = \App\Models\Procurement\Platform\Registry::query()->findOrFail({$registryId});
    app(\App\Support\Database\PostgresAdvisoryLock::class)->acquireProcurement(
        identity: app(\App\Services\Procurement\Acquisition\IdentityResolver::class)->forRegistry(\$registry->id, '0173100000123000001'),
    );

    if (file_put_contents({$lockReadyPath}, 'locked') === false) {
        throw new \RuntimeException('Could not publish the held procurement lock.');
    }

    \$deadline = microtime(true) + 10;
    \$waitingPid = null;

    while (microtime(true) < \$deadline) {
        \$contents = file_get_contents({$waitingPidPath});

        if (\$contents !== false && trim(\$contents) !== '') {
            \$waitingPid = (int) trim(\$contents);
            break;
        }

        usleep(1000);
    }

    if (\$waitingPid === null) {
        throw new \RuntimeException('Second ingest worker did not publish its backend pid.');
    }

    \$isWaiting = false;

    while (microtime(true) < \$deadline) {
        \$isWaiting = (bool) \Illuminate\Support\Facades\DB::scalar(
            'select exists (select 1 from pg_locks where pid = ? and locktype = ? and not granted)',
            [\$waitingPid, 'advisory'],
        );

        if (\$isWaiting) {
            break;
        }

        usleep(1000);
    }

    if (! \$isWaiting) {
        throw new \RuntimeException('Second ingest worker did not wait for the procurement advisory lock.');
    }

    \$snapshot = new \App\Dto\Procurement\ProcurementSnapshotData(
        registryId: \$registry->id,
        identity: app(\App\Services\Procurement\Acquisition\IdentityResolver::class)->forRegistry(\$registry->id, '0173100000123000001')->source,
        title: 'Поставка бумаги офисной',
        sourceStage: 'Подача заявок',
        publishedAt: \Illuminate\Support\Carbon::parse('2026-01-10 10:00:00'),
        sourceUpdatedAt: \Illuminate\Support\Carbon::parse('2026-01-10 10:00:00'),
        initialPrice: '150000.00',
    );
    \$procurement = app(\App\Services\Procurement\Acquisition\SnapshotFinalizer::class)->ingest(data: \$snapshot);
    \$backendPid = \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()');
    \$connection->commit();

    return [
        'procurement_id' => \$procurement->id,
        'backend_pid' => \$backendPid,
        'waiting_backend_pid' => \$waitingPid,
    ];
} catch (\Throwable \$exception) {
    \$connection->rollBack();

    throw \$exception;
}
PHP;
}

function concurrentWaitingSecondIngestWorker(int $registryId, string $lockReadyPath, string $waitingPidPath): string
{
    $lockReadyPath = var_export($lockReadyPath, true);
    $waitingPidPath = var_export($waitingPidPath, true);

    return <<<PHP
\$deadline = microtime(true) + 10;
\$contents = null;

while (microtime(true) < \$deadline) {
    \$contents = file_get_contents({$lockReadyPath});

    if (\$contents === 'locked') {
        break;
    }

    usleep(1000);
}

if (\$contents !== 'locked') {
    throw new \RuntimeException('First ingest worker did not acquire the procurement lock.');
}

\Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-01-10 10:00:00'));
\$backendPid = \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()');

if (file_put_contents({$waitingPidPath}, (string) \$backendPid) === false) {
    throw new \RuntimeException('Could not publish the waiting ingest backend pid.');
}

\$registry = \App\Models\Procurement\Platform\Registry::query()->findOrFail({$registryId});
\$snapshot = new \App\Dto\Procurement\ProcurementSnapshotData(
    registryId: \$registry->id,
    identity: app(\App\Services\Procurement\Acquisition\IdentityResolver::class)->forRegistry(\$registry->id, '0173100000123000001')->source,
    title: 'Поставка бумаги офисной',
    sourceStage: 'Подача заявок',
    publishedAt: \Illuminate\Support\Carbon::parse('2026-01-10 10:00:00'),
    sourceUpdatedAt: \Illuminate\Support\Carbon::parse('2026-01-10 10:00:00'),
    initialPrice: '175000.00',
);
\$procurement = app(\App\Services\Procurement\Acquisition\SnapshotFinalizer::class)->ingest(data: \$snapshot);

return [
    'procurement_id' => \$procurement->id,
    'backend_pid' => \$backendPid,
];
PHP;
}

/**
 * @return array{revision_numbers: list<int>, revision_hashes: list<string>, revision_ids: list<int>, detected_at: list<string>, revision_count: int, current_revision_id: int, current_revision_number: int, events: list<string>}
 */
function concurrentIngestState(int $registryId): array
{
    return ConcurrentTransactions::execute(<<<PHP
\$procurement = \App\Models\Procurement\Procurement::query()
    ->where('registry_id', {$registryId})
    ->where('source_identity_value', '0173100000123000001')
    ->sole();
\$revisions = \App\Models\Procurement\ProcurementRevision::query()
    ->where('procurement_id', \$procurement->id)
    ->orderBy('revision_number')
    ->get();

return [
    'revision_numbers' => \$revisions->pluck('revision_number')->all(),
    'revision_hashes' => \$revisions->pluck('snapshot_hash')->all(),
    'revision_ids' => \$revisions->pluck('id')->all(),
    'detected_at' => \$revisions->map(
        fn (\App\Models\Procurement\ProcurementRevision \$revision): string => \$revision->detected_at->format('Y-m-d H:i:s'),
    )->all(),
    'revision_count' => \$procurement->revision_count,
    'current_revision_id' => \$procurement->current_revision_id,
    'current_revision_number' => (int) \App\Models\Procurement\ProcurementRevision::query()
        ->whereKey(\$procurement->current_revision_id)
        ->value('revision_number'),
    'events' => \App\Models\Procurement\ProcurementEvent::query()
        ->where('procurement_id', \$procurement->id)
        ->orderBy('id')
        ->pluck('event_type')
        ->all(),
];
PHP);
}

function concurrentIngestSignalPath(string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);

    if ($path === false) {
        throw new RuntimeException('Could not create a concurrency signal path.');
    }

    return $path;
}

function concurrentIngestRemoveSignal(string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }
}

function concurrentIngestCleanup(int $registryId): void
{
    ConcurrentTransactions::execute(<<<PHP
\$platformId = \App\Models\Procurement\Platform\Registry::query()
    ->whereKey({$registryId})
    ->value('platform_id');
\$procurements = \App\Models\Procurement\Procurement::query()->where('registry_id', {$registryId})->get();

foreach (\$procurements as \$procurement) {
    \App\Models\Procurement\ProcurementEvent::query()->where('procurement_id', \$procurement->id)->delete();
    \App\Models\Procurement\ProcurementRevision::query()->where('procurement_id', \$procurement->id)->delete();
    \$procurement->delete();
}

\App\Models\Procurement\Platform\StatusMapping::query()->where('registry_id', {$registryId})->delete();
\App\Models\Procurement\Platform\Registry::query()->whereKey({$registryId})->delete();
\App\Models\Procurement\Platform\Platform::query()->whereKey(\$platformId)->delete();

return ['cleaned' => true];
PHP);
}
