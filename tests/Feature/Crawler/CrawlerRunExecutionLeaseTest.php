<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\CrawlerRunManager;
use Tests\Support\Postgres\ConcurrentTransactions;

dataset('execution lease owners', [
    'backlog subject' => [RunPurpose::Backlog, 'fresh-run:901'],
    'delta owner' => [RunPurpose::Delta, 'fresh-crawler:902'],
]);

test('два PostgreSQL процесса впускают в execution lease только одного owner и expiry продолжает тот же cursor', function (RunPurpose $purpose, string $subjectKey): void {
    $fixture = ConcurrentTransactions::execute(<<<PHP
\$crawler = \App\Models\Procurement\Crawler\Crawler::factory()->create();
\$run = \App\Models\Procurement\Crawler\Run::factory()->for(\$crawler)->create([
    'purpose' => \App\Enums\Procurement\Crawler\RunPurpose::{$purpose->name},
    'subject_key' => '{$subjectKey}',
    'cursor' => ['blockFrom' => 501, 'day' => '2026-09-10'],
]);

return [
    'crawler_id' => \$crawler->id,
    'run_id' => \$run->id,
    'registry_id' => \$crawler->registry_id,
    'platform_id' => \$crawler->registry->platform_id,
];
PHP);

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => executionLeaseWorker(runId: $fixture['run_id'], blockFrom: 601),
            'second' => executionLeaseWorker(runId: $fixture['run_id'], blockFrom: 701),
        ]);

        $entered = array_filter($workers, fn (array $worker): bool => $worker['entered']);
        $beforeTakeover = ConcurrentTransactions::execute(<<<PHP
\$run = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['run_id']});

return [
    'block_from' => \$run->cursor['blockFrom'],
    'owner_token' => \$run->execution_owner_token,
];
PHP);

        expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            ->and($entered)->toHaveCount(1)
            ->and($beforeTakeover['owner_token'])->not->toBeNull()
            ->and($beforeTakeover['block_from'])->toBeIn([601, 701]);

        $takeover = ConcurrentTransactions::execute(<<<PHP
\App\Models\Procurement\Crawler\Run::query()->whereKey({$fixture['run_id']})->update([
    'execution_lease_expires_at' => \Illuminate\Support\Facades\DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 second'"),
]);

\$run = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['run_id']});
\$manager = app(\App\Services\Procurement\CrawlerRunManager::class);
\$resumedBlockFrom = \$run->cursor['blockFrom'];
\$acquired = \$manager->acquireExecutionLease(run: \$run);

if (\$acquired) {
    \$manager->updateCursor(run: \$run, cursor: new \App\Dto\Procurement\Crawler\ListingCursor(blockFrom: 801, day: '2026-09-10'));
    \$manager->complete(run: \$run);
}

return ['acquired' => \$acquired, 'resumed_block_from' => \$resumedBlockFrom];
PHP);

        $completed = ConcurrentTransactions::execute(<<<PHP
\$run = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['run_id']});

return [
    'status' => \$run->status->value,
    'block_from' => \$run->cursor['blockFrom'],
    'owner_token' => \$run->execution_owner_token,
];
PHP);

        expect($takeover['acquired'])->toBeTrue()
            ->and($takeover['resumed_block_from'])->toBe($beforeTakeover['block_from'])
            ->and($completed['status'])->toBe(RunStatus::Completed->value)
            ->and($completed['block_from'])->toBe(801)
            ->and($completed['owner_token'])->toBeNull();
    } finally {
        executionLeaseCleanup(fixture: $fixture);
    }
})->with('execution lease owners');

test('не владеющий token не меняет cursor, status или lease', function (): void {
    $run = Run::factory()->create([
        'cursor' => ['blockFrom' => 501],
    ]);
    $manager = app(CrawlerRunManager::class);
    $owner = Run::query()->findOrFail($run->id);
    $nonOwner = Run::query()->findOrFail($run->id);

    expect($manager->acquireExecutionLease(run: $owner))->toBeTrue()
        ->and($manager->updateCursor(run: $nonOwner, cursor: new ListingCursor(blockFrom: 601)))->toBeFalse()
        ->and($manager->complete(run: $nonOwner))->toBeFalse()
        ->and($manager->releaseExecutionLease(run: $nonOwner))->toBeFalse();

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->cursor['blockFrom'])->toBe(501)
        ->and($run->execution_owner_token)->not->toBeNull();

    expect($manager->releaseExecutionLease(run: $owner))->toBeTrue();
});

test('прежний owner после takeover не меняет cursor, stats, status или lease', function (): void {
    $run = Run::factory()->create([
        'cursor' => ['blockFrom' => 501],
        'stats' => ['checkpoints' => 3],
    ]);
    $firstManager = app(CrawlerRunManager::class);
    $firstOwner = Run::query()->findOrFail($run->id);

    expect($firstManager->acquireExecutionLease(run: $firstOwner))->toBeTrue();

    Run::query()->whereKey($run->id)->update([
        'execution_lease_expires_at' => DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 second'"),
    ]);

    $secondManager = new CrawlerRunManager;
    $secondOwner = Run::query()->findOrFail($run->id);

    expect($secondManager->acquireExecutionLease(run: $secondOwner))->toBeTrue()
        ->and($secondManager->updateCursor(run: $secondOwner, cursor: new ListingCursor(blockFrom: 601)))->toBeTrue();

    expect(fn () => $firstManager->updateCursor(run: $firstOwner, cursor: new ListingCursor(blockFrom: 701)))
        ->toThrow(RuntimeException::class)
        ->and($firstManager->updateStats(run: $firstOwner, stats: ['checkpoints' => 99]))->toBeFalse()
        ->and($firstManager->complete(run: $firstOwner))->toBeFalse()
        ->and($firstManager->releaseExecutionLease(run: $firstOwner))->toBeFalse();

    expect($run->refresh()->status)->toBe(RunStatus::Running)
        ->and($run->cursor['blockFrom'])->toBe(601)
        ->and($run->stats['checkpoints'])->toBe(3)
        ->and($run->execution_owner_token)->not->toBeNull();
});

/** @return array{entered: bool, backend_pid: int} */
function executionLeaseWorker(int $runId, int $blockFrom): string
{
    return <<<PHP
\$run = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$runId});
\$manager = app(\App\Services\Procurement\CrawlerRunManager::class);
\$entered = \$manager->acquireExecutionLease(run: \$run);

if (\$entered) {
    \$manager->updateCursor(run: \$run, cursor: new \App\Dto\Procurement\Crawler\ListingCursor(blockFrom: {$blockFrom}, day: '2026-09-10'));
}

return ['entered' => \$entered, 'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()')];
PHP;
}

/** @param array{crawler_id: int, run_id: int, registry_id: int, platform_id: int} $fixture */
function executionLeaseCleanup(array $fixture): void
{
    ConcurrentTransactions::execute(<<<PHP
\App\Models\Procurement\Crawler\Run::query()->whereKey({$fixture['run_id']})->delete();
\App\Models\Procurement\Crawler\Crawler::query()->whereKey({$fixture['crawler_id']})->delete();
\App\Models\Procurement\Platform\Registry::query()->whereKey({$fixture['registry_id']})->delete();
\App\Models\Procurement\Platform\Platform::query()->whereKey({$fixture['platform_id']})->delete();

return ['cleaned' => true];
PHP);
}
