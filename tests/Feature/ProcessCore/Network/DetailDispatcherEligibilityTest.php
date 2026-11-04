<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\StageCode;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Services\Procurement\Network\AcquisitionEligibility;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\NetworkFixtures;

beforeEach(fn () => F::clean());
afterEach(fn () => F::clean());

test('canonical detail exclusion preserves due work and lets another platform use the batch', function (string $hold): void {
    ExecutionFixtures::authority([StageCode::Detail]);
    $a = NetworkFixtures::platform('held');
    $b = NetworkFixtures::platform('available');
    $sync = app(SourceCatalogSync::class);
    $sync->sync(NetworkFixtures::catalog('held'));
    $sync->sync(NetworkFixtures::catalog('available'));
    $blocked = DB::transaction(fn () => ExecutionFixtures::detailWork());
    $eligible = DB::transaction(fn () => ExecutionFixtures::detailWork());
    foreach ([[$blocked, $a], [$eligible, $b]] as [$work, $platform]) {
        $row = ExecutionFixtures::work($work);
        $registry = Registry::factory()->for($platform)->create();
        DB::table('prc_procurements')->where('id', $row->procurement_id)->update(['registry_id' => $registry->id, 'platform_id' => $platform->id]);
        DB::table('prc_process_work')->where('id', $work->value->value)->update(['platform_id' => $platform->id]);
    }
    $before = AcquisitionProfileFixtures::workSnapshot($blocked->value->value);
    if ($hold === 'paused') {
        app(ProcessTransaction::class)->run(fn () => app(ControlRepository::class)->setMode(
            'acquisition:platform:held', ControlMode::Paused, null, 'fixture pause', 1, LedgerFixtures::uuid(), LedgerFixtures::uuid()));
    } elseif ($hold === 'retired') {
        DB::table('prc_source_targets')->where('platform_id', $a->id)->update(['retired_at' => DB::raw('clock_timestamp()')]);
    } else {
        DB::table('prc_target_health')->whereIn('target_id', DB::table('prc_source_targets')->select('id')->where('platform_id', $a->id))
            ->update(['state' => 'open', 'open_until' => DB::raw("clock_timestamp() + interval '10 minutes'")]);
    }
    $excluded = app(AcquisitionEligibility::class)->excludedPlatforms(StageCode::Detail);
    $batch = DB::table('prc_process_work')->where('state', 'ready')->whereNotIn('platform_id', $excluded)->limit(1)->pluck('id')->all();
    expect($excluded)->toContain($a->id)->not->toContain($b->id)
        ->and($batch)->toBe([$eligible->value->value])
        ->and(AcquisitionProfileFixtures::workSnapshot($blocked->value->value))->toBe($before)
        ->and(ExecutionFixtures::work($blocked)->state)->toBe('ready')
        ->and(DB::table('prc_process_attempts')->count())->toBe(0);
})->with(['paused', 'target_open', 'retired'])->group('network-route-policy');
