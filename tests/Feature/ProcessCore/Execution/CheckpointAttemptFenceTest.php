<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\CheckpointStatus;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;

pest()->group('claim-recovery-and-invariants', 'work-controls-and-fences', 'daemon-reconciliation');

function checkpointFenceLeaseUntil(string $workId): string
{
    $leaseUntil = DB::selectOne('SELECT lease_until::text AS lease_until FROM prc_process_work WHERE id = ?', [$workId])->lease_until ?? null;

    return is_string($leaseUntil) ? $leaseUntil : throw new LogicException('Running work has no lease_until.');
}

test('checkpoint закрытого attempt не продлевает lease и возвращает lost', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    expect(DB::update(
        'UPDATE prc_process_attempts SET finished_at = clock_timestamp() WHERE id = ? AND finished_at IS NULL',
        [$lease->attemptId->value],
    ))->toBe(1);
    $before = checkpointFenceLeaseUntil($work->value->value);

    $result = app(WorkStoreRepository::class)->checkpoint($lease, new FixtureStageCheckpoint(3), 0);

    expect($result->status)->toBe(CheckpointStatus::LostOwnership)
        ->and(checkpointFenceLeaseUntil($work->value->value))->toBe($before)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('checkpoint_seq'))->toBe(0)
        ->and(DB::selectOne(
            'SELECT cursor::text AS cursor FROM prc_crawl_blocks WHERE id = (SELECT crawl_block_id FROM prc_process_work WHERE id = ?)',
            [$work->value->value],
        )->cursor)->toBe('{}')
        ->and(DB::table('prc_process_attempts')->where('id', $lease->attemptId->value)->whereNotNull('finished_at')->value('last_checkpoint_at'))->toBeNull();
});

test('checkpoint живого незавершённого attempt сохраняет progress и тот же lease', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);

    $saved = app(WorkStoreRepository::class)->checkpoint($lease, new FixtureStageCheckpoint(3), 0);
    $sameLease = LedgerRow::one(
        'SELECT (lease_until = ?::timestamptz) AS same FROM prc_process_work WHERE id = ?',
        [$saved->leaseUntil?->value->format('Y-m-d H:i:s.uP'), $work->value->value],
    );

    expect($saved->status)->toBe(CheckpointStatus::Saved)
        ->and($sameLease?->bool('same'))->toBeTrue()
        ->and(DB::table('prc_process_attempts')->where('id', $lease->attemptId->value)->whereNull('finished_at')->value('last_checkpoint_at'))->not->toBeNull();
});
