<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures as P;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

beforeEach(fn () => ListingFixtures::bind());

test('process run resolves a numeric profile and commits required fresh work without dispatch', function (): void {
    $profile = P::create();
    Queue::fake();
    $this->artisan('process:run', ['profile' => (string) $profile->id])->assertSuccessful();
    expect(DB::table('prc_process_runs')->where('profile_id', $profile->id)->count())->toBe(1)
        ->and(DB::table('prc_crawl_runs')->sole()->listing_kind)->toBe('fresh')
        ->and(DB::table('prc_process_work')->sole()->state)->toBe('ready')
        ->and(DB::table('prc_process_run_work')->sole()->accounting_role)->toBe('required');
    Queue::assertNothingPushed();
});

test('process run with a sync queue leaves discovery collecting until the durable executor finishes', function (): void {
    config(['queue.connections.redis.driver' => 'sync']);
    $profile = P::create();
    $this->artisan('process:run', ['profile' => (string) $profile->id])->assertSuccessful();
    expect(DB::table('prc_process_runs')->sole()->discovery_outcome)->toBe('collecting')
        ->and(DB::table('prc_process_work')->sole()->state)->toBe('ready')
        ->and(DB::table('prc_process_attempts')->count())->toBe(0)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('process run resolves a profile name and records its immutable settings', function (): void {
    $profile = P::create();
    $profile->update(['name' => 'eis-fresh-profile']);
    $this->artisan('process:run', ['profile' => 'eis-fresh-profile'])->assertSuccessful();
    $run = DB::table('prc_process_runs')->sole();
    expect($run->profile_id)->toBe($profile->id)
        ->and(json_decode($run->configuration_snapshot, true)['profile_version'])->toBe($profile->profile_version)
        ->and(P::cursors($profile)[0]->dateField)->toBe('publish');
});

test('process run rejects a missing profile id or name without any allocation', function (): void {
    foreach (['999999', 'no-such-profile'] as $identifier) {
        $this->artisan('process:run', ['profile' => $identifier])
            ->expectsOutputToContain('profile not found')->assertFailed();
    }
    expect(DB::table('prc_process_runs')->count())->toBe(0)
        ->and(DB::table('prc_process_work')->count())->toBe(0);
});

test('minute producer allocates active scheduled fresh profiles and ignores inactive or unscheduled profiles', function (): void {
    $first = P::create();
    $second = P::create();
    $archive = P::create(AcquisitionKind::Archive);
    $archive->update(['schedule' => null]);
    $inactive = P::create();
    $inactive->update(['is_active' => false]);
    $this->artisan('process:schedule-acquisition')->assertSuccessful();
    expect(DB::table('prc_process_runs')->orderBy('profile_id')->pluck('profile_id')->all())->toBe([$first->id, $second->id])
        ->and(DB::table('prc_crawl_runs')->where('listing_kind', 'fresh')->count())->toBe(2);
});

test('one unresolved profile cannot prevent another scheduled profile from allocating', function (): void {
    $healthy = P::create();
    $broken = P::create();
    $broken->update(['filter' => new EisSearchFilter(okpd2Codes: ['99'])]);
    $this->artisan('process:schedule-acquisition')->assertSuccessful();
    expect(DB::table('prc_process_runs')->where('profile_id', $healthy->id)->count())->toBe(1)
        ->and(DB::table('prc_process_runs')->where('profile_id', $broken->id)->count())->toBe(0)
        ->and(DB::table('prc_acquisition_schedules')->where('profile_id', $broken->id)->whereRaw('next_due_at<=clock_timestamp()')->count())->toBe(1);
});

test('repeating a command while its work is running preserves the current owner and one run', function (): void {
    $profile = P::create();
    $command = (string) Str::uuid();
    $arguments = ['profile' => (string) $profile->id, '--command-id' => $command];
    $this->artisan('process:run', $arguments)->assertSuccessful();
    $row = DB::table('prc_process_work')->sole();
    FinalizeFixtures::running(new WorkId(new ProcessUuid($row->id)));
    $before = P::workSnapshot($row->id);
    $this->artisan('process:run', $arguments)->assertSuccessful();
    expect(P::workSnapshot($row->id))->toBe($before)
        ->and(DB::table('prc_process_runs')->count())->toBe(1);
});

test('an existing manual run does not prevent scheduled allocation for another profile', function (): void {
    $busy = P::create();
    $free = P::create();
    $this->artisan('process:run', ['profile' => (string) $busy->id])->assertSuccessful();
    $work = DB::table('prc_process_work')->sole();
    FinalizeFixtures::running(new WorkId(new ProcessUuid($work->id)));
    $before = P::workSnapshot($work->id);
    $this->artisan('process:schedule-acquisition')->assertSuccessful();
    expect(DB::table('prc_process_runs')->where('profile_id', $free->id)->count())->toBe(1)
        ->and(P::workSnapshot($work->id))->toBe($before)
        ->and(DB::table('prc_process_runs')->where('kind', 'schedule')->count())->toBe(2);
});
