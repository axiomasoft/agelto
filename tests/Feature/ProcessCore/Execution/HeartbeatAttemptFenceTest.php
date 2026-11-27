<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\Core\StageCode;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;

pest()->group('claim-recovery-and-invariants', 'work-controls-and-fences', 'daemon-reconciliation');

function heartbeatFenceLeaseUntil(string $workId): string
{
    $leaseUntil = DB::selectOne('SELECT lease_until::text AS lease_until FROM prc_process_work WHERE id = ?', [$workId])->lease_until ?? null;

    return is_string($leaseUntil) ? $leaseUntil : throw new LogicException('Running work has no lease_until.');
}

test('heartbeat закрытого attempt не продлевает lease и возвращает lost', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    expect(DB::update(
        'UPDATE prc_process_attempts SET finished_at = clock_timestamp() WHERE id = ? AND finished_at IS NULL',
        [$lease->attemptId->value],
    ))->toBe(1);
    $before = heartbeatFenceLeaseUntil($work->value->value);

    $renewal = app(WorkStoreRepository::class)->heartbeat($lease);

    expect($renewal->isRenewed())->toBeFalse()
        ->and(heartbeatFenceLeaseUntil($work->value->value))->toBe($before)
        ->and(DB::table('prc_process_attempts')->where('id', $lease->attemptId->value)->whereNotNull('finished_at')->value('last_heartbeat_at'))->toBeNull();
});

test('heartbeat живого незавершённого attempt продлевает lease', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);

    $renewal = app(WorkStoreRepository::class)->heartbeat($lease);
    $sameLease = LedgerRow::one(
        'SELECT (lease_until = ?::timestamptz) AS same FROM prc_process_work WHERE id = ?',
        [$renewal->leaseUntil?->value->format('Y-m-d H:i:s.uP'), $work->value->value],
    );

    expect($renewal->isRenewed())->toBeTrue()
        ->and($sameLease?->bool('same'))->toBeTrue()
        ->and(DB::table('prc_process_attempts')->where('id', $lease->attemptId->value)->whereNull('finished_at')->value('last_heartbeat_at'))->not->toBeNull();
});
