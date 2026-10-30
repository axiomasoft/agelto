<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\ControlMode;
use App\Jobs\Procurement\FetchProcurementDetailJob;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Services\Procurement\DetailDispatcher;
use App\Services\Procurement\DetailRefetchSelector;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\NetworkFixtures;

beforeEach(fn () => F::clean());
afterEach(fn () => F::clean());

test('canonical detail exclusion preserves due work and lets another platform use the batch', function (string $hold): void {
    $a = NetworkFixtures::platform('held');
    $b = NetworkFixtures::platform('available');
    $sync = app(SourceCatalogSync::class);
    $sync->sync(NetworkFixtures::catalog('held'));
    $sync->sync(NetworkFixtures::catalog('available'));
    $ra = Registry::factory()->for($a)->create();
    $rb = Registry::factory()->for($b)->create();
    $blocked = Procurement::factory()->for($ra)->create(['detail_failed' => false, 'detail_fetched_at' => null, 'detail_next_retry_at' => now()->subMinute()]);
    $eligible = Procurement::factory()->for($rb)->create(['detail_failed' => false, 'detail_fetched_at' => null, 'detail_next_retry_at' => now()->subMinute()]);
    $before = $blocked->refresh()->getRawOriginal();
    SourceCircuit::factory()->create(['platform_id' => $b->id, 'manually_paused' => true]);
    if ($hold === 'paused') {
        app(ProcessTransaction::class)->run(fn () => app(ControlRepository::class)->setMode(
            'acquisition:platform:held', ControlMode::Paused, null, 'fixture pause', 1, LedgerFixtures::uuid(), LedgerFixtures::uuid()));
    } elseif ($hold === 'retired') {
        DB::table('prc_source_targets')->where('platform_id', $a->id)->update(['retired_at' => DB::raw('clock_timestamp()')]);
    } else {
        DB::table('prc_target_health')->whereIn('target_id', DB::table('prc_source_targets')->select('id')->where('platform_id', $a->id))
            ->update(['state' => 'open', 'open_until' => DB::raw("clock_timestamp() + interval '10 minutes'")]);
    }
    Bus::fake([FetchProcurementDetailJob::class]);
    expect(app(DetailDispatcher::class)->dispatchDue(1))->toBe(1);
    Bus::assertDispatched(FetchProcurementDetailJob::class, fn ($job): bool => $job->procurementId === $eligible->id);
    Bus::assertNotDispatched(FetchProcurementDetailJob::class, fn ($job): bool => $job->procurementId === $blocked->id);
    expect($blocked->refresh()->getRawOriginal())->toEqual($before)
        ->and(app(DetailRefetchSelector::class)->due(10)->pluck('id')->all())->toContain($blocked->id)
        ->and(SourceCircuit::query()->count())->toBe(1);
})->with(['paused', 'target_open', 'retired'])->group('t04', 'p26');
