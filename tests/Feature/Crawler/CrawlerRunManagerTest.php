<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\WorkFinalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures as F;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;

beforeEach(function (): void {
    F::authority([StageCode::Listing]);
    FixtureProviderRecoveryResolver::install();
});

test('Process recovery revokes only an expired lease and preserves a live concurrent work', function (): void {
    $expired = E::listingWork();
    $live = E::listingWork();
    $old = F::running($expired);
    $current = F::running($live);
    F::expire($expired);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and(E::work($expired)->state)->toBe('ready')->and(E::work($expired)->owner_token)->toBeNull()
        ->and(E::work($live)->state)->toBe('running')->and(E::work($live)->owner_token)->toBe($current->token->value)
        ->and(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(0)
        ->and(app(WorkFinalizer::class)->finish($old, F::succeeded(StageCode::Listing, 'expired'))->status->value)->toBe('lost_ownership');
});

test('Process schema rejects an owner token without an execution lease before it can become stale', function (): void {
    $work = E::listingWork();
    F::running($work);
    $before = AcquisitionProfileFixtures::workSnapshot($work->value->value);
    expect(fn () => DB::transaction(fn () => DB::table('prc_process_work')->where('id', $work->value->value)->update(['lease_until' => null])))
        ->toThrow(QueryException::class)
        ->and(AcquisitionProfileFixtures::workSnapshot($work->value->value))->toBe($before);
});

test('Process execution lease uses PostgreSQL clock independently of frozen PHP dates', function (): void {
    $work = E::listingWork();
    $lease = F::running($work);
    try {
        Carbon::setTestNow('2030-01-01');
        CarbonImmutable::setTestNow('2030-01-01');
        expect(app(WorkStoreRepository::class)->heartbeat($lease)->isRenewed())->toBeTrue();
        F::expire($work);
        Carbon::setTestNow('2000-01-01');
        CarbonImmutable::setTestNow('2000-01-01');
        expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
        $next = F::running($work);
        expect($next->generation)->toBeGreaterThan($lease->generation)
            ->and((bool) DB::scalar('SELECT lease_until>clock_timestamp() FROM prc_process_work WHERE id=?', [$work->value->value]))->toBeTrue();
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
});

test('Process heartbeat refreshes the database timestamp and lease through the owning token', function (): void {
    $work = E::listingWork();
    $lease = F::running($work);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['heartbeat_at' => DB::raw("clock_timestamp()-interval '10 minutes'")]);
    expect(app(WorkStoreRepository::class)->heartbeat($lease)->isRenewed())->toBeTrue()
        ->and((bool) DB::scalar("SELECT heartbeat_at>clock_timestamp()-interval '2 seconds' AND lease_until>clock_timestamp()
            FROM prc_process_work WHERE id=?", [$work->value->value]))->toBeTrue();
});

test('Process checkpoint sequence advances only on successful CAS and preserves immutable input and other progress', function (): void {
    $work = E::listingWork();
    $lease = F::running($work);
    $store = app(WorkStoreRepository::class);
    $input = E::work($work)->input_payload;
    $one = $store->checkpoint($lease, new FixtureStageCheckpoint(501), 0);
    expect($one->status->value)->toBe('saved')->and($one->checkpointSeq)->toBe(1);
    expect($store->checkpoint($lease, new FixtureStageCheckpoint(999), 0)->status->value)->toBe('stale_checkpoint');
    $two = $store->checkpoint($lease->checkpointed($one), new FixtureStageCheckpoint(701), 1);
    expect($two->status->value)->toBe('saved')->and($two->checkpointSeq)->toBe(2)
        ->and(E::work($work)->input_payload)->toBe($input)
        ->and((int) DB::table('prc_crawl_blocks')->where('id', E::work($work)->crawl_block_id)->value('cursor->position'))->toBe(701);
});

test('EIS cursor rejects legacy unversioned defaults and roundtrips explicit cap and partition policy', function (): void {
    $cursor = new EisListingCursor('2026-08-01', 'update', 1, 100001, ContentHash::of('scope'), 501, 3, 500);
    expect(EisListingCursor::decode($cursor->envelope()))->toEqual($cursor);
    expect(fn () => EisListingCursor::decode(new CursorEnvelope('eis.listing', 1, '{"blockFrom":501}')))
        ->toThrow(InvalidArgumentException::class);
});

test('Process terminal publication is idempotent and a repeated stale completion changes no accepted result', function (): void {
    $work = E::listingWork();
    $lease = F::running($work);
    expect(app(WorkFinalizer::class)->finish($lease, F::succeeded(StageCode::Listing, 'original'))->status->value)->toBe('applied');
    $before = AcquisitionProfileFixtures::workSnapshot($work->value->value);
    expect(app(WorkFinalizer::class)->finish($lease, F::succeeded(StageCode::Listing, 'duplicate'))->status->value)->toBe('lost_ownership')
        ->and(AcquisitionProfileFixtures::workSnapshot($work->value->value))->toBe($before)
        ->and(DB::table('prc_process_transitions')->where('work_id', $work->value->value)->where('new_state', 'succeeded')->count())->toBe(1)
        ->and(DB::table('prc_crawl_blocks')->where('id', E::work($work)->crawl_block_id)->value('terminal_proof->artifact'))->toBe('results/original');
});
