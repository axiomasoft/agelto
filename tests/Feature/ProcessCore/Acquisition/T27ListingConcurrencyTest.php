<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;

test('T27 two PostgreSQL finalizers accept one page and one successor graph', function (): void {
    $result = ConcurrentTransactions::execute(<<<'PHP'
\Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
$registry = null;
try {
    \Tests\Support\ProcessCore\ListingFixtures::bind();
    $registry = \App\Models\Procurement\Platform\Registry::factory()->eis()->create();
    $run=\Tests\Support\ProcessCore\ListingFixtures::run(registry: $registry); $lease=\Tests\Support\ProcessCore\FinalizeFixtures::running($run['work']);
    $serialized=base64_encode(serialize([$lease,$run['input']]));
    $operation = '\Tests\Support\ProcessCore\ListingFixtures::bindLocal(); [$lease,$input]=unserialize(base64_decode("'.$serialized.'"));
        $result=new \App\Dto\Procurement\Process\StageSucceeded(new \App\Dto\Procurement\Acquisition\ListingResult(
          app(\App\Repositories\Procurement\Crawler\ListingStoreRepository::class)->task($input),
          \Tests\Support\ProcessCore\ListingFixtures::page([\Tests\Support\ProcessCore\ListingFixtures::summary("concurrent")] )));
        ';
    $call='fn()=>app(\App\Services\Procurement\Process\WorkFinalizer::class)->finish($lease,$result)->status->value';
    $results=\Tests\Support\Postgres\ConcurrentTransactions::concurrently([
        'holder'=>$operation.'return ["outcome"=>\Tests\Support\ProcessCore\FinalizeFixtures::holdUntilWaiter('.$call.')];',
        'waiter'=>$operation.'return ["outcome"=>\Tests\Support\ProcessCore\FinalizeFixtures::afterHolder('.$call.')];',
    ]);
    return ['registry_id'=>$registry->id,'results'=>$results,'receipts'=>\Illuminate\Support\Facades\DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count(),
        'works'=>\Illuminate\Support\Facades\DB::table('prc_process_work')->count(), 'attempts'=>\Illuminate\Support\Facades\DB::table('prc_process_attempts')->whereNotNull('finished_at')->count(),
        'links'=>\Illuminate\Support\Facades\DB::table('prc_process_run_work')->where('run_id',$run['run']->value)->count(),
        'procurements'=>\Illuminate\Support\Facades\DB::table('prc_procurements')->where('source_identity_value','concurrent')->count()];
} finally {
    \Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
    \Illuminate\Support\Facades\DB::statement('TRUNCATE prc_procurements CASCADE');
    $registry?->delete();
}
PHP);
    expect($result)->toMatchArray(['receipts' => 1, 'works' => 3, 'attempts' => 1, 'links' => 3, 'procurements' => 1]);
    expect($result['results'])->toBe(['holder' => ['outcome' => 'applied'], 'waiter' => ['outcome' => 'lost_ownership']]);
    expect(DB::table('prc_registries')->where('id', $result['registry_id'])->exists())->toBeFalse();
})->group('t07', 't09', 't27', 'p23');
