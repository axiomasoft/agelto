<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ExecutionLease;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\WorkFinalizer;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures as F;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\LedgerFixtures;

dataset('execution lease owners', [
    'backlog subject' => ['backlog', 'fresh-run:901'],
    'delta owner' => ['delta', 'fresh-crawler:902'],
]);

test('два PostgreSQL процесса впускают в execution lease только одного owner и expiry продолжает тот же cursor', function (string $purpose, string $subjectKey): void {
    $result = ConcurrentTransactions::execute('$cohort='.var_export($purpose.':'.$subjectKey, true).';'.<<<'PHP'
\Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
try {
    \Tests\Support\ProcessCore\FinalizeFixtures::authority([\App\Enums\Procurement\Process\StageCode::Listing]);
    $work = \Tests\Support\ProcessCore\ExecutionFixtures::listingWork();
    \Illuminate\Support\Facades\DB::table('prc_process_runs')->update(['trigger_key' => $cohort]);
    app(\App\Services\Procurement\Process\WorkFeeder::class)->tick('acquisition');
    $worker = static function (int $position) use ($work): string {
        return '$work=unserialize(base64_decode('.var_export(base64_encode(serialize($work)), true).'));'
            .'\Tests\Support\ProcessCore\FinalizeFixtures::bind(\App\Enums\Procurement\Process\StageCode::Listing);'
            .'$claim=app(\App\Repositories\Procurement\Process\WorkStoreRepository::class)->claim($work,new \App\Dto\Procurement\Process\DeliveryGeneration(1));'
            .'$lease=$claim->lease; if($lease!==null){$saved=app(\App\Repositories\Procurement\Process\WorkStoreRepository::class)->checkpoint($lease,new \Tests\Support\ProcessCore\FixtureStageCheckpoint('.$position.'),0);$lease=$lease->checkpointed($saved);}'
            .'return ["status"=>$claim->status->value,"lease"=>$lease===null?null:\Tests\Support\ProcessCore\FinalizeFixtures::export($lease),"pid"=>\Illuminate\Support\Facades\DB::scalar("SELECT pg_backend_pid()")];';
    };
    $workers = \Tests\Support\Postgres\ConcurrentTransactions::concurrently(['one'=>$worker(601),'two'=>$worker(701)]);
    $winners = array_values(array_filter($workers, static fn(array $r): bool=>$r['lease']!==null));
    if(count($winners)!==1){throw new \RuntimeException('Claim did not choose exactly one PostgreSQL owner');}
    $old = \Tests\Support\ProcessCore\FinalizeFixtures::import($winners[0]['lease']);
    $position = $old->checkpoint->stageProgress->position;
    \Tests\Support\ProcessCore\FinalizeFixtures::expire($work);
    \Tests\Support\ProcessCore\FixtureProviderRecoveryResolver::install();
    $recovered = app(\App\Services\Procurement\Process\RecoveryReconciler::class)->recoverExpiredRunning();
    $next = \Tests\Support\ProcessCore\FinalizeFixtures::running($work);
    $resumed = $next->checkpoint->stageProgress->position;
    $stale = app(\App\Services\Procurement\Process\WorkFinalizer::class)->finish($old,
        \Tests\Support\ProcessCore\FinalizeFixtures::succeeded(\App\Enums\Procurement\Process\StageCode::Listing,'late'));
    $saved = app(\App\Repositories\Procurement\Process\WorkStoreRepository::class)->checkpoint($next,
        new \Tests\Support\ProcessCore\FixtureStageCheckpoint(801),$next->checkpointSeq);
    $done = app(\App\Services\Procurement\Process\WorkFinalizer::class)->finish($next->checkpointed($saved),
        \Tests\Support\ProcessCore\FinalizeFixtures::succeeded(\App\Enums\Procurement\Process\StageCode::Listing,'complete'));
    $row = \Tests\Support\ProcessCore\ExecutionFixtures::work($work);
    return ['different_backends'=>$workers['one']['pid']!==$workers['two']['pid'],'owners'=>count($winners),
        'position'=>$position,'resumed'=>$resumed,'recovered'=>$recovered,'generation'=>$row->generation,'old_generation'=>$old->generation,'current_generation'=>$next->generation,
        'final_position'=>(int)\Illuminate\Support\Facades\DB::table('prc_crawl_blocks')->where('id',$row->crawl_block_id)->value('cursor->position'),
        'stale'=>$stale->status->value,'finished'=>$done->status->value,'state'=>$row->state,'owner'=>$row->owner_token];
} finally {
    \Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
}
PHP);
    expect($result['different_backends'])->toBeTrue()->and($result['owners'])->toBe(1)
        ->and($result['position'])->toBeIn([601, 701])->and($result['resumed'])->toBe($result['position'])
        // Recovery revokes the old generation; the next claim grants another generation.
        ->and($result['recovered'])->toBe(1)->and($result['current_generation'])->toBe($result['old_generation'] + 2)
        ->and($result['generation'])->toBe($result['current_generation'])
        ->and($result['final_position'])->toBe(801)
        ->and($result['stale'])->toBe('lost_ownership')->and($result['finished'])->toBe('applied')
        ->and($result['state'])->toBe('succeeded')->and($result['owner'])->toBeNull();
})->with('execution lease owners');

function forgedProcessLease(ExecutionLease $owner): ExecutionLease
{
    return new ExecutionLease($owner->workId, $owner->attemptId, $owner->generation, LedgerFixtures::uuid(),
        $owner->leaseUntil, $owner->inputRevision, $owner->inputHash, $owner->checkpointSeq, $owner->checkpoint, $owner->stage, $owner->handlerVersion);
}

test('не владеющий token не меняет cursor, status или lease', function (): void {
    F::authority([StageCode::Listing]);
    $work = E::listingWork();
    $lease = F::running($work);
    $other = forgedProcessLease($lease);
    $before = AcquisitionProfileFixtures::workSnapshot($work->value->value);
    expect(app(WorkStoreRepository::class)->checkpoint($other, new FixtureStageCheckpoint(601), 0)->status->value)->toBe('lost_ownership')
        ->and(app(WorkStoreRepository::class)->heartbeat($other)->isRenewed())->toBeFalse()
        ->and(app(WorkFinalizer::class)->finish($other, F::succeeded(StageCode::Listing, 'forged'))->status->value)->toBe('lost_ownership')
        ->and(AcquisitionProfileFixtures::workSnapshot($work->value->value))->toBe($before)
        ->and(app(WorkStoreRepository::class)->heartbeat($lease)->isRenewed())->toBeTrue();
});

test('прежний owner после takeover не меняет cursor, stats, status или lease', function (): void {
    F::authority([StageCode::Listing]);
    $work = E::listingWork();
    $old = F::running($work);
    $saved = app(WorkStoreRepository::class)->checkpoint($old, new FixtureStageCheckpoint(501), 0);
    $old = $old->checkpointed($saved);
    F::expire($work);
    FixtureProviderRecoveryResolver::install();
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $current = F::running($work);
    $saved = app(WorkStoreRepository::class)->checkpoint($current, new FixtureStageCheckpoint(601), $current->checkpointSeq);
    $before = AcquisitionProfileFixtures::workSnapshot($work->value->value);
    expect(app(WorkStoreRepository::class)->checkpoint($old, new FixtureStageCheckpoint(701), $old->checkpointSeq)->status->value)->toBe('lost_ownership')
        ->and(app(WorkStoreRepository::class)->heartbeat($old)->isRenewed())->toBeFalse()
        ->and(app(WorkFinalizer::class)->finish($old, F::succeeded(StageCode::Listing, 'late'))->status->value)->toBe('lost_ownership')
        ->and(AcquisitionProfileFixtures::workSnapshot($work->value->value))->toBe($before)
        ->and(app(WorkFinalizer::class)->finish($current->checkpointed($saved), F::succeeded(StageCode::Listing, 'current'))->status->value)->toBe('applied');
});
