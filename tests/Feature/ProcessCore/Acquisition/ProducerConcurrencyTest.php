<?php

declare(strict_types=1);

use Tests\Support\Postgres\ConcurrentTransactions;

test('two due producers serialize on the schedule and allocate one required graph', function (): void {
    $result = ConcurrentTransactions::execute(<<<'PHP'
\Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
$profile = null;
$platformBefore = \App\Models\Procurement\Platform\Platform::query()->where('code', 'eis')->value('id');
$registryBefore = \App\Models\Procurement\Platform\Registry::query()->where('platform_id', $platformBefore)->where('code', 'fz44')->value('id');
try {
    \Tests\Support\ProcessCore\ListingFixtures::bind();
    $profile = \Database\Factories\Platforms\Eis\EisCrawlerFactory::new()->create();
    $partitioner = new \App\Services\Platforms\Eis\EisListingPartitioner;
    $filter = new \App\Dto\Platforms\Eis\EisListingFilter(['123']);
    $launch = new \App\Dto\Procurement\Acquisition\Control\AcquisitionLaunch(
        $profile->id, 1, $profile->registry->platform_id, $profile->registry_id, $profile->registry->code,
        \App\Enums\Procurement\Process\Core\AcquisitionKind::Fresh, 'fixture-listing/1', $partitioner->snapshot($filter),
        \App\Dto\Procurement\Process\Primitives\ContentHash::of('access'), \App\Dto\Procurement\Process\Primitives\ContentHash::of('stream'),
        $partitioner->plan($filter, '2026-01-01', '2026-01-01'), new \App\Dto\Procurement\Process\Primitives\VersionedPayload('{"profile_version":1}', 1));
    app(\App\Services\Procurement\Acquisition\Orchestration\AcquisitionScheduler::class)->configure(
        $profile->id, \App\Enums\Procurement\Process\Core\AcquisitionKind::Fresh, '0 0 1 1 *', 'UTC', 1);
    \Illuminate\Support\Facades\DB::update("UPDATE prc_acquisition_schedules SET next_due_at='2026-01-01 00:00:00+00' WHERE profile_id=?", [$profile->id]);
    $before = \Illuminate\Support\Facades\DB::table('prc_acquisition_schedules')->where('profile_id', $profile->id)->value('next_due_at');
    // Only trusted, locally constructed DTOs cross this test-process boundary.
    $bind = '\Tests\Support\ProcessCore\ListingFixtures::bind(); $launch=unserialize(base64_decode('.var_export(base64_encode(serialize($launch)), true).')); ';
    $call = 'fn()=>app(\App\Services\Procurement\Acquisition\Orchestration\AcquisitionScheduler::class)->due($launch)?->value';
    $results = \Tests\Support\Postgres\ConcurrentTransactions::concurrently([
        'holder' => $bind.'return ["run"=>\Tests\Support\ProcessCore\FinalizeFixtures::holdUntilWaiter('.$call.')];',
        'waiter' => $bind.'return ["run"=>\Tests\Support\ProcessCore\FinalizeFixtures::afterHolder('.$call.')];',
    ]);
    return ['holder_created' => is_string($results['holder']['run']), 'waiter' => $results['waiter']['run'],
        'runs' => \Illuminate\Support\Facades\DB::table('prc_process_runs')->count(),
        'works' => \Illuminate\Support\Facades\DB::table('prc_process_work')->count(),
        'required' => \Illuminate\Support\Facades\DB::table('prc_process_run_work')->where('accounting_role', 'required')->count(),
        'due_advanced' => \Illuminate\Support\Facades\DB::table('prc_acquisition_schedules')->where('profile_id', $profile->id)->value('next_due_at') !== $before];
} finally {
    \Tests\Support\ProcessCore\ExecutionFixtures::truncateLedger();
    if ($profile !== null) {
        $registry = $profile->registry;
        $platform = $registry->platform;
        \Illuminate\Support\Facades\DB::table('prc_acquisition_schedules')->where('profile_id', $profile->id)->delete();
        $profile->delete();
        if ($registryBefore === null) {
            $registry->delete();
        }
        if ($platformBefore === null) {
            $platform->delete();
        }
    }
}
PHP);
    expect($result)->toBe(['holder_created' => true, 'waiter' => null, 'runs' => 1, 'works' => 1,
        'required' => 1, 'due_advanced' => true]);
})->group('acquisition-operations', 'run-state-reduction');
