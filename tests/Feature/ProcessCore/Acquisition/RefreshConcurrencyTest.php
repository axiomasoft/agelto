<?php

declare(strict_types=1);

use Tests\Support\Postgres\ConcurrentTransactions;

test('two schedulers allocate one due status cycle under the same real PostgreSQL head lock', function (): void {
    $result = ConcurrentTransactions::execute(<<<'PHP'
\Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
$registry=null;
try {
    \Tests\Support\ProcessCore\DetailFixtures::bind();
    $registry=\App\Models\Procurement\Platform\Registry::factory()->eis()->create();
    $f=\Tests\Support\ProcessCore\DetailFixtures::work($registry);
    app(\App\Services\Procurement\Process\Execution\WorkFinalizer::class)->finish(\Tests\Support\ProcessCore\FinalizeFixtures::running($f['work']),
        new \App\Dto\Procurement\Process\Outcomes\StageSucceeded(new \App\Dto\Procurement\Acquisition\Detail\DetailResult(
            \Tests\Support\ProcessCore\DetailFixtures::snapshot($f['request'],'attributes'),new \App\Dto\Procurement\Acquisition\Detail\DetailProgress,\App\Enums\Procurement\Platform\SnapshotConsistency::BoundedRevalidated,new DateTimeImmutable)));
    $call='fn()=>app(\App\Repositories\Procurement\Crawler\RefreshRepository::class)->scheduleStatus('.$f['p']->id.')?->value->value';
    $bind='\Tests\Support\ProcessCore\DetailFixtures::bindLocal(); ';
    $results=\Tests\Support\Postgres\ConcurrentTransactions::concurrently([
        'holder'=>$bind.'return ["work"=>\Tests\Support\ProcessCore\FinalizeFixtures::holdUntilWaiter('.$call.')];',
        'waiter'=>$bind.'return ["work"=>\Tests\Support\ProcessCore\FinalizeFixtures::afterHolder('.$call.')];',
    ]);
    return ['created'=>is_string($results['holder']['work']),'duplicate'=>$results['waiter']['work'],
        'cycles'=>\Illuminate\Support\Facades\DB::table('prc_status_refreshes')->count(),
        'work'=>\Illuminate\Support\Facades\DB::table('prc_process_work')->where('stage','status_refresh')->count(),
        'links'=>\Illuminate\Support\Facades\DB::table('prc_process_run_work')->count()];
} finally {
    \Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
    \Illuminate\Support\Facades\DB::statement('TRUNCATE prc_procurements CASCADE');
    $registry?->delete();
}
PHP);
    expect($result)->toBe(['created' => true, 'duplicate' => null, 'cycles' => 1, 'work' => 1, 'links' => 1]);
})->group('acquisition-operations');

test('remap and a new source head serialize without losing revision history', function (bool $remapFirst): void {
    $setup = '$remapFirst='.($remapFirst ? 'true' : 'false').';';
    $result = ConcurrentTransactions::execute($setup.<<<'PHP'
\Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
$registry=null;
try {
    \Tests\Support\ProcessCore\DetailFixtures::bind();
    $registry=\App\Models\Procurement\Platform\Registry::factory()->eis()->create();
    $f=\Tests\Support\ProcessCore\DetailFixtures::work($registry, 'remap-race', 'raw-race');
    \App\Models\Procurement\Platform\StatusMapping::query()->where('registry_id',$registry->id)->where('source_stage','raw-race')->update(['canonical_status'=>'evaluation']);
    $remap='fn()=>app(\App\Services\Procurement\Acquisition\Status\StatusRemapper::class)->remap('.$f['p']->id.',1)';
    $new='fn()=>app(\App\Services\Procurement\Acquisition\Detail\SnapshotFinalizer::class)->ingest(\Tests\Support\Procurement\SnapshotFactory::make(\App\Models\Procurement\Platform\Registry::findOrFail('.$registry->id.'),["registryNumber"=>"remap-race","title"=>"new-head","sourceStage"=>"Подача заявок"]))->title';
    $bind='\Tests\Support\ProcessCore\DetailFixtures::bindLocal(); ';
    $results=\Tests\Support\Postgres\ConcurrentTransactions::concurrently([
        'holder'=>$bind.'return ["value"=>\Tests\Support\ProcessCore\FinalizeFixtures::holdUntilWaiter('.($remapFirst?$remap:$new).')];',
        'waiter'=>$bind.'return ["value"=>\Tests\Support\ProcessCore\FinalizeFixtures::afterHolder('.($remapFirst?$new:$remap).')];',
    ]);
    return ['results'=>$results,'title'=>$f['p']->refresh()->title,'revisions'=>$f['p']->revision_count,
        'head'=>$f['p']->head_generation,'events'=>\Illuminate\Support\Facades\DB::table('prc_events')->where('event_type','status_changed')->count()];
} finally {
    \Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
    \Illuminate\Support\Facades\DB::statement('TRUNCATE prc_procurements CASCADE');
    $registry?->delete();
}
PHP);
    expect($result['title'])->toBe('new-head')->and($result['revisions'])->toBe($remapFirst ? 3 : 2)
        ->and($result['head'])->toBe($remapFirst ? 3 : 2)->and($result['events'])->toBe($remapFirst ? 3 : 1);
    expect($result['results'])->toBe($remapFirst
        ? ['holder' => ['value' => true], 'waiter' => ['value' => 'new-head']]
        : ['holder' => ['value' => 'new-head'], 'waiter' => ['value' => false]]);
})->with([true, false])->group('unknown-status-mapping');
