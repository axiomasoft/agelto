<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelRun;
use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Acquisition\ListingTask;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Enums\Procurement\Process\ControlMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Services\Procurement\Acquisition\AcquisitionProfiles;
use App\Services\Procurement\Acquisition\ListingRecovery;
use App\Services\Procurement\Process\WorkFeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures as P;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\ListingFixtures as F;

beforeEach(fn () => F::bind());

function processRecoveryDebt(): array
{
    $profile = P::create(AcquisitionKind::Fresh);
    $launch = app(AcquisitionProfiles::class)->prepare($profile, AcquisitionKind::Fresh,
        new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-03'));
    $run = F::run('2026-01-01', '2026-01-03', budget: 1, registry: $profile->registry);
    DB::table('prc_crawl_runs')->where('id', $run['crawl']->value)->update(['listing_kind' => AcquisitionKind::Fresh->value]);
    DB::table('prc_process_runs')->where('id', $run['run']->value)->update([
        'profile_id' => $profile->id, 'configuration_snapshot' => $launch->configuration->json,
        'configuration_schema_version' => $launch->configuration->schemaVersion,
    ]);
    F::finish($run['work'], $run['input'], F::page());
    $next = F::next($run['crawl']);
    F::finish($next['work'], $next['input'], F::page());

    return [$profile, $run, $launch];
}

test('Process reconciliation uses the original fresh scope after profile edits without an archive profile', function (): void {
    [$profile, $run, $launch] = processRecoveryDebt();
    $saved = app(ListingCoverageRepository::class)->read($run['stream'])->debt[0]->filter->serializedPayload;
    $profile->update(['filter' => new EisSearchFilter(stageApplications: false, stageCompleted: true, okpd2Codes: ['43'], priceTo: 5000)]);
    expect(Crawler::query()->where('listing_kind', AcquisitionKind::Archive)->exists())->toBeFalse()
        ->and(app(ListingRecovery::class)->due($profile))->toBe(1)
        ->and(app(ListingRecovery::class)->due($profile))->toBe(0);
    $recovery = DB::table('prc_process_runs')->where('trigger_key', 'like', 'reconcile:%')->sole();
    expect(json_decode($recovery->configuration_snapshot, true))->toEqual(json_decode($launch->configuration->json, true))
        ->and($recovery->profile_id)->toBe($profile->id)
        ->and(DB::table('prc_crawl_runs')->where('process_run_id', $recovery->id)->value('filter_payload'))->not->toBeNull();
    $blocks = DB::table('prc_crawl_blocks as b')->join('prc_crawl_runs as c', 'c.id', '=', 'b.crawl_run_id')->where('c.process_run_id', $recovery->id)->pluck('b.partition_payload');
    foreach ($blocks as $block) {
        $task = ListingTask::fromJson($block);
        expect($task->filter->serializedPayload)->toBe($saved)->and($task->streamKey->hex)->toBe($run['stream']->hex);
    }
    expect(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('Process reconciliation acknowledges coverage only after complete stable recovery passes', function (): void {
    [$profile, $run] = processRecoveryDebt();
    expect(app(ListingRecovery::class)->due($profile))->toBe(1);
    $crawl = new ProcessUuid(DB::table('prc_crawl_runs as c')->join('prc_process_runs as r', 'r.id', '=', 'c.process_run_id')
        ->where('r.trigger_key', 'like', 'reconcile:%')->value('c.id'));
    $n = 0;
    while (($next = F::next($crawl)) !== null) {
        F::finish($next['work'], $next['input'], F::page());
        if (++$n === 1) {
            expect(app(ListingCoverageRepository::class)->read($run['stream'])->debt)->not->toBe([]);
        }
        expect($n)->toBeLessThanOrEqual(4);
    }
    $coverage = app(ListingCoverageRepository::class)->read($run['stream']);
    expect($n)->toBe(4)->and($coverage->verifiedThrough)->toBe('2026-01-03')->and($coverage->debt)->toBe([])
        ->and(app(ListingRecovery::class)->due($profile))->toBe(0);
});

test('Process reconciliation pause and intent cancellation never erase unresolved coverage debt', function (bool $cancel): void {
    [$profile, $run] = processRecoveryDebt();
    app(ListingRecovery::class)->due($profile);
    $recovery = DB::table('prc_process_runs')->where('trigger_key', 'like', 'reconcile:%')->sole();
    if ($cancel) {
        app(CancelRun::class)->execute(new ProcessUuid($recovery->id), 'cancel recovery intent', $recovery->transition_seq);
        expect(DB::table('prc_process_runs')->where('id', $recovery->id)->value('discovery_outcome'))->toBe('incomplete');
    } else {
        E::setMode('acquisition', ControlMode::Paused);
        expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0);
    }
    $coverage = app(ListingCoverageRepository::class)->read($run['stream']);
    expect($coverage->verifiedThrough)->toBe('2026-01-01')->and($coverage->debt)->toHaveCount(3)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
    if (! $cancel) {
        expect(app(ListingRecovery::class)->due($profile))->toBe(0);
    }
})->with(['pause' => [false], 'cancel intent' => [true]]);
