<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\AcquisitionKind;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Services\Procurement\Acquisition\AcquisitionProfiles;
use App\Services\Procurement\Acquisition\ListingRecovery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures as P;
use Tests\Support\ProcessCore\ListingFixtures as F;

beforeEach(fn () => F::bind());

test('Process recovery replaces backlog command with idempotent durable continuation allocation', function (): void {
    $profile = P::create(AcquisitionKind::Archive);
    $launch = app(AcquisitionProfiles::class)->prepare($profile, AcquisitionKind::Archive,
        new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-03'));
    $f = F::run('2026-01-01', '2026-01-03', budget: 1, registry: $profile->registry);
    DB::table('prc_process_runs')->where('id', $f['run']->value)->update([
        'profile_id' => $profile->id, 'configuration_snapshot' => $launch->configuration->json,
        'configuration_schema_version' => $launch->configuration->schemaVersion,
    ]);
    F::finish($f['work'], $f['input'], F::page());
    $pass = F::next($f['crawl']) ?? throw new LogicException('Missing full pass');
    F::finish($pass['work'], $pass['input'], F::page());
    $coverage = app(ListingCoverageRepository::class)->read($f['stream']);
    expect($coverage->debt)->toHaveCount(1)->and($coverage->debt[0]->windowStart)->toBe('2026-01-02');
    expect(app(ListingRecovery::class)->due($profile))->toBe(1)
        ->and(app(ListingRecovery::class)->due($profile))->toBe(0)
        ->and(DB::table('prc_process_runs')->where('trigger_key', 'like', 'reconcile:%')->count())->toBe(1)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->count())->toBe(2)
        ->and(array_key_exists('crawler:rescan-backlog', Artisan::all()))->toBeFalse()
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('Process recovery without coverage debt allocates nothing', function (): void {
    $profile = P::create();
    $before = DB::table('prc_process_runs')->count();
    expect(app(ListingRecovery::class)->due($profile))->toBe(0)
        ->and(DB::table('prc_process_runs')->count())->toBe($before)
        ->and(DB::table('prc_process_work')->count())->toBe(0);
});
