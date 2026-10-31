<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Enums\Procurement\Process\AcquisitionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures as P;
use Tests\Support\ProcessCore\ListingFixtures;

beforeEach(fn () => ListingFixtures::bind());

test('process run allocates archive day roots for explicit bounds without direct dispatch', function (): void {
    $profile = P::create(AcquisitionKind::Archive);
    Queue::fake();
    $this->artisan('process:run', ['profile' => (string) $profile->id, '--kind' => 'archive',
        '--from' => '2024-01-01', '--to' => '2024-01-01'])->assertSuccessful();
    expect(DB::table('prc_crawl_runs')->sole()->listing_kind)->toBe('archive')
        ->and(P::cursors($profile))->toHaveCount(1)
        ->and(P::cursors($profile)[0]->day)->toBe('2024-01-01')
        ->and(DB::table('prc_process_run_work')->sole()->accounting_role)->toBe('required');
    Queue::assertNothingPushed();
});

test('archive launch with a sync queue cannot report success before work publication', function (): void {
    $profile = P::create(AcquisitionKind::Archive);
    config(['queue.connections.redis.driver' => 'sync']);
    $this->artisan('process:run', ['profile' => (string) $profile->id, '--kind' => 'archive',
        '--from' => '2024-01-01', '--to' => '2024-01-01'])->assertSuccessful();
    expect(DB::table('prc_process_runs')->sole()->discovery_outcome)->toBe('collecting')
        ->and(DB::table('prc_process_work')->sole()->state)->toBe('ready')
        ->and(DB::table('prc_process_attempts')->count())->toBe(0);
});

test('process archive run resolves the profile by name', function (): void {
    $profile = P::create(AcquisitionKind::Archive);
    $profile->update(['name' => 'eis-archive-profile']);
    $this->artisan('process:run', ['profile' => 'eis-archive-profile', '--kind' => 'archive',
        '--from' => '2024-01-01', '--to' => '2024-01-01'])->assertSuccessful();
    expect(DB::table('prc_process_runs')->sole()->profile_id)->toBe($profile->id)
        ->and(P::cursors($profile)[0]->day)->toBe('2024-01-01');
});

test('process archive run rejects missing profile ids and names without a run', function (): void {
    foreach (['999999', 'no-such-archive'] as $identifier) {
        $this->artisan('process:run', ['profile' => $identifier, '--kind' => 'archive', '--from' => '2024-01-01'])
            ->expectsOutputToContain('profile not found')->assertFailed();
    }
    expect(DB::table('prc_process_runs')->count())->toBe(0)
        ->and(DB::table('prc_crawl_blocks')->count())->toBe(0);
});

test('archive launch without a start bound fails closed when the profile has no date window', function (): void {
    $profile = P::create(AcquisitionKind::Archive);
    $profile->update(['filter' => new EisSearchFilter(okpd2Codes: ['43'])]);
    $this->artisan('process:run', ['profile' => (string) $profile->id, '--kind' => 'archive'])
        ->expectsOutputToContain('requires a start date')->assertFailed();
    expect(DB::table('prc_process_runs')->count())->toBe(0);
});

test('archive launch uses the immutable profile date window when bounds are omitted', function (): void {
    $profile = P::create(AcquisitionKind::Archive);
    $profile->update(['filter' => new EisSearchFilter(okpd2Codes: ['43'],
        publishedFrom: CarbonImmutable::parse('2025-01-01'), publishedTo: CarbonImmutable::parse('2025-12-31'))]);
    $this->artisan('process:run', ['profile' => (string) $profile->id, '--kind' => 'archive'])->assertSuccessful();
    $days = array_map(fn ($cursor) => $cursor->day, P::cursors($profile));
    sort($days);
    expect($days)->toHaveCount(365)->and($days[0])->toBe('2025-01-01')->and($days[364])->toBe('2025-12-31');
});

test('explicit archive bounds override the profile window and preserve every declared day', function (): void {
    $profile = P::create(AcquisitionKind::Archive);
    $profile->update(['filter' => new EisSearchFilter(okpd2Codes: ['43'],
        publishedFrom: CarbonImmutable::parse('2025-01-01'), publishedTo: CarbonImmutable::parse('2025-12-31'))]);
    $this->artisan('process:run', ['profile' => (string) $profile->id, '--kind' => 'archive',
        '--from' => '2024-03-01', '--to' => '2024-03-31'])->assertSuccessful();
    $days = array_map(fn ($cursor) => $cursor->day, P::cursors($profile));
    sort($days);
    expect($days)->toHaveCount(31)->and($days[0])->toBe('2024-03-01')->and($days[30])->toBe('2024-03-31')
        ->and(DB::table('prc_process_run_work')->where('accounting_role', 'required')->count())->toBe(31);
});
