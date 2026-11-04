<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\AdapterValidationClaim;
use App\Dto\Procurement\Network\TransportDeferred;
use Tests\Support\Postgres\ConcurrentTransactions;

test('work and standalone validation owners serialize on one adapter capacity', function (): void {
    $result = ConcurrentTransactions::execute(<<<'PHP'
\Tests\Support\ProcessCore\Http\PermitFixtures::clean();
\Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
try {
    $f = new \Tests\Support\ProcessCore\Http\PermitFixtures;
    $contract = new \App\Dto\Procurement\Network\AdapterContractRef($f->code, 'detail', '1', '1');
    app(\App\Services\Procurement\Network\SourceCatalogSync::class)->sync(new \App\Dto\Procurement\Platform\SourceTargetDefinitions($f->code, [
        new \App\Dto\Procurement\Platform\SourceTargetDefinition('card', 'detail', $f->url->origin, [$f->profile], adapterContract: $contract),
    ]));
    \Illuminate\Support\Facades\DB::table('prc_adapter_health')->update(['state'=>'healthy']);
    $platform = \Illuminate\Support\Facades\DB::table('prc_platforms')->where('code',$f->code)->sole()->id;
    \Tests\Support\ProcessCore\FinalizeFixtures::authority([\App\Enums\Procurement\Process\StageCode::Listing]);
    $work = \Tests\Support\ProcessCore\ExecutionFixtures::listingWork();
    \Illuminate\Support\Facades\DB::table('prc_process_work')->where('id',$work->value->value)->update(['platform_id'=>$platform]);
    $lease = \Tests\Support\ProcessCore\FinalizeFixtures::running($work);
    $operation = $f->context();
    \Illuminate\Support\Facades\DB::table('prc_outbound_operations')->where('id',$operation->lease()->id->value)->update(['platform_id'=>$platform]);
    $common = '$contract=unserialize(base64_decode('.var_export(base64_encode(serialize($contract)),true).')); ';
    $holder = $common.'$lease=unserialize(base64_decode('.var_export(base64_encode(serialize($lease)),true).')); ';
    $holder .= '\Tests\Support\ProcessCore\ExecutionFixtures::registerHandler(\App\Enums\Procurement\Process\StageCode::Listing); ';
    $holder .= '$context=new \App\Dto\Procurement\Process\ExecutionContext($lease, app(\App\Repositories\Procurement\Process\WorkStoreRepository::class), app(\App\Services\Procurement\Process\Contracts\ProcessClock::class), \App\Dto\Procurement\Process\Duration::seconds(240), \App\Dto\Procurement\Process\Duration::seconds(360), \App\Dto\Procurement\Process\Duration::seconds(15), \App\Dto\Procurement\Process\Duration::seconds(15), \App\Dto\Procurement\Process\Duration::seconds(30)); ';
    $waiter = $common.'$context=\Tests\Support\ProcessCore\Http\PermitFixtures::importContext('.var_export($operation->lease()->id->value,true).'); ';
    $call = 'fn()=>app(\App\Services\Procurement\Process\ProcessTransaction::class)->run(fn()=>app(\App\Repositories\Procurement\Network\AdapterHealthRepository::class)->admit($contract,$context))::class';
    $results = \Tests\Support\Postgres\ConcurrentTransactions::concurrently([
        'holder'=>$holder.'return ["result"=>\Tests\Support\ProcessCore\FinalizeFixtures::holdUntilWaiter('.$call.')];',
        'waiter'=>$waiter.'return ["result"=>\Tests\Support\ProcessCore\FinalizeFixtures::afterHolder('.$call.')];',
    ]);
    return ['holder'=>$results['holder']['result'], 'waiter'=>$results['waiter']['result'],
        'attempts'=>\Illuminate\Support\Facades\DB::table('prc_process_attempts')->whereNotNull('adapter_health_id')->count(),
        'operations'=>\Illuminate\Support\Facades\DB::table('prc_outbound_operations')->whereNotNull('adapter_health_id')->count()];
} finally {
    \Tests\Support\ProcessCore\Http\PermitFixtures::clean();
    \Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
}
PHP);
    expect($result)->toBe([
        'holder' => AdapterValidationClaim::class,
        'waiter' => TransportDeferred::class,
        'attempts' => 1, 'operations' => 0,
    ]);
})->group('network-admission-probes', 'provider-send-fencing');
