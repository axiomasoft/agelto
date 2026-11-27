<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Procurement\Acquisition\Listing\ListingTask;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Acquisition\Listing\AcquisitionProfiles;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionScheduler;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures as P;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures as F;

beforeEach(fn () => F::bind());

test('Process delta uses its own update windows and needs no separate archive profile', function (): void {
    $profile = P::create(AcquisitionKind::Fresh);
    $profiles = app(AcquisitionProfiles::class);
    $delta = $profiles->prepare($profile, AcquisitionKind::Delta, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-03'));
    $archive = $profiles->prepare($profile, AcquisitionKind::Archive, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-03'));
    $scheduler = app(AcquisitionScheduler::class);
    $a = $scheduler->manual($archive, LedgerFixtures::uuid(), null);
    $before = DB::table('prc_process_runs')->where('id', $a->value)->value('configuration_snapshot');
    $d = $scheduler->manual($delta, LedgerFixtures::uuid(), null);
    expect($d)->not->toEqual($a)->and($delta->plan->windows)->toHaveCount(3)
        ->and(Crawler::query()->where('listing_kind', AcquisitionKind::Archive)->count())->toBe(0)
        ->and(DB::table('prc_process_runs')->where('id', $a->value)->value('configuration_snapshot'))->toBe($before)
        ->and($delta->streamKey->hex)->not->toBe($archive->streamKey->hex);
    foreach ($delta->plan->windows as $window) {
        $cursor = EisListingCursor::decode($window->cursor);
        expect($cursor->dateField)->toBe('update')->and($cursor->firstRow)->toBe(1)
            ->and($cursor->filterHash->hex)->toBe((new EisListingPartitioner)->restoreFilter($delta->filter)->fingerprint()->hex);
    }
    expect(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('Process delta covers the requested update days without claiming success before stability', function (): void {
    $profile = P::create();
    $launch = app(AcquisitionProfiles::class)->prepare($profile, AcquisitionKind::Delta,
        new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-03'));
    $run = app(AcquisitionScheduler::class)->manual($launch, LedgerFixtures::uuid(), null);
    $crawl = new ProcessUuid(DB::table('prc_crawl_runs')->where('process_run_id', $run->value)->value('id'));
    $n = 0;
    while (($next = F::next($crawl)) !== null) {
        F::finish($next['work'], $next['input'], F::page());
        expect(++$n)->toBeLessThanOrEqual(6);
        if ($n === 1) {
            expect(DB::table('prc_process_runs')->where('id', $run->value)->value('discovery_outcome'))->toBe('collecting');
        }
    }
    expect($n)->toBe(6)->and(DB::table('prc_process_runs')->where('id', $run->value)->value('discovery_outcome'))->toBe('complete')
        ->and(app(ListingCoverageRepository::class)->read($launch->streamKey)->verifiedThrough)->toBe('2026-01-03');
});

test('Process delta pause between days prohibits the next grant and keeps the update cursor', function (): void {
    $profile = P::create();
    $launch = app(AcquisitionProfiles::class)->prepare($profile, AcquisitionKind::Delta,
        new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-02'));
    $run = app(AcquisitionScheduler::class)->manual($launch, LedgerFixtures::uuid(), null);
    $crawl = new ProcessUuid(DB::table('prc_crawl_runs')->where('process_run_id', $run->value)->value('id'));
    for ($i = 0; $i < 2; $i++) {
        $row = DB::table('prc_process_work as w')->join('prc_crawl_blocks as b', 'b.id', '=', 'w.crawl_block_id')
            ->where('b.crawl_run_id', $crawl->value)->where('b.partition_payload->windowStart', '2026-01-01')
            ->whereIn('w.state', ['ready', 'admitted'])->first(['w.id', 'w.input_payload']);
        $input = app(ProcessPayloadCodec::class)->decodeInput(new VersionedPayload($row->input_payload, 1));
        F::finish(new WorkId(new ProcessUuid($row->id)), $input, F::page());
    }
    E::setMode('acquisition', ControlMode::Paused);
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(app(ListingCoverageRepository::class)->read($launch->streamKey)->verifiedThrough)->toBe('2026-01-01');
    $task = ListingTask::fromJson(DB::table('prc_crawl_blocks')->where('crawl_run_id', $crawl->value)
        ->where('partition_payload->windowStart', '2026-01-02')->value('partition_payload'));
    expect(EisListingCursor::decode($task->cursor)->dateField)->toBe('update')
        ->and(DB::table('prc_process_work as w')->join('prc_crawl_blocks as b', 'b.id', '=', 'w.crawl_block_id')
            ->where('b.crawl_run_id', $crawl->value)->where('b.partition_payload->windowStart', '2026-01-02')->value('w.execution_attempts'))->toBe(0);
});
