<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelRun;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Procurement\Acquisition\AcquisitionConfiguration;
use App\Dto\Procurement\Acquisition\AcquisitionLaunch;
use App\Dto\Procurement\Acquisition\ListingPlan;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Models\Procurement\Crawler\Crawler;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Acquisition\AcquisitionScheduler;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ListingFixtures;

pest()->group('t17', 't18', 'p23');

beforeEach(fn () => ListingFixtures::bind());

function producerLaunch(Crawler $profile, AcquisitionKind $kind, bool $empty = false): AcquisitionLaunch
{
    $partitioner = new EisListingPartitioner;
    $filter = new EisListingFilter(['123']);

    return new AcquisitionLaunch($profile->id, 1, $profile->registry->platform_id, $profile->registry_id,
        $profile->registry->code, $kind, 'fixture-listing/1', $partitioner->snapshot($filter),
        ContentHash::of('access'), ContentHash::of('stream:'.$kind->value),
        $empty ? new ListingPlan([]) : $partitioner->plan($filter, '2026-01-01', '2026-01-01'),
        new VersionedPayload('{"profile_version":1}', 1));
}

test('T17 одна scheduled тройка создаёт один run, разные acquisition kinds создают разные runs', function (): void {
    $profile = EisCrawlerFactory::new()->create();
    $producer = app(AcquisitionScheduler::class);
    $slot = new DateTimeImmutable('2026-01-01T12:00:00+03:00');
    $archive = producerLaunch($profile, AcquisitionKind::Archive);
    $first = $producer->scheduled($archive, $slot);
    expect($producer->scheduled($archive, new DateTimeImmutable('2026-01-01T09:00:00Z'))->value)->toBe($first->value);
    $delta = $producer->scheduled(producerLaunch($profile, AcquisitionKind::Delta), $slot);
    expect($delta->value)->not->toBe($first->value)
        ->and(DB::table('prc_process_runs')->count())->toBe(2)
        ->and(DB::table('prc_process_work')->count())->toBe(2)
        ->and(DB::table('prc_process_run_work')->where('accounting_role', 'required')->count())->toBe(2)
        ->and(DB::table('prc_process_runs')->where('id', $first->value)->value('trigger_key'))
        ->toBe('schedule:'.$profile->id.':archive:2026-01-01T09:00:00Z');
});

test('T17 manual command UUID сохраняется при повторе, закрытый пустой discovery завершён явно', function (): void {
    $profile = EisCrawlerFactory::new()->create();
    $producer = app(AcquisitionScheduler::class);
    $launch = producerLaunch($profile, AcquisitionKind::Fresh, empty: true);
    $command = ListingStoreRepository::id('manual-command');
    $id = $producer->manual($launch, $command, null);
    expect($producer->manual($launch, $command, null)->value)->toBe($id->value)
        ->and(DB::table('prc_process_runs')->count())->toBe(1)
        ->and(DB::table('prc_process_runs')->value('discovery_outcome'))->toBe('complete')
        ->and(DB::table('prc_process_runs')->value('discovery_closed_at'))->not->toBeNull()
        ->and(DB::table('prc_process_work')->count())->toBe(0);
    expect(fn () => $producer->manual(producerLaunch($profile, AcquisitionKind::Archive, empty: true), $command, null))
        ->toThrow(InvalidArgumentException::class, 'another immutable acquisition request');
});

test('T18 real producers share required detail work and cancelling one run preserves the other requirement', function (): void {
    $profile = EisCrawlerFactory::new()->create();
    $scheduler = app(AcquisitionScheduler::class);
    $launch = producerLaunch($profile, AcquisitionKind::Fresh);
    $runs = [
        $scheduler->manual($launch, ListingStoreRepository::id('shared-producer-1'), null),
        $scheduler->manual($launch, ListingStoreRepository::id('shared-producer-2'), null),
    ];
    foreach ($runs as $run) {
        $crawl = new ProcessUuid(DB::table('prc_crawl_runs')->where('process_run_id', $run->value)->sole()->id);
        $root = ListingFixtures::next($crawl) ?? throw new LogicException('Missing producer root.');
        ListingFixtures::finish($root['work'], $root['input'], ListingFixtures::page([ListingFixtures::summary('shared-source')]));
    }
    $detail = DB::table('prc_process_work')->where('stage', 'detail')->sole();
    expect(DB::table('prc_procurements')->count())->toBe(1)
        ->and(DB::table('prc_process_run_work')->where('work_id', $detail->id)->where('accounting_role', 'required')->count())->toBe(2);
    $seq = DB::table('prc_process_runs')->where('id', $runs[0]->value)->value('transition_seq');
    app(CancelRun::class)->execute($runs[0], 'cancel first producer', $seq);
    expect(DB::table('prc_process_work')->where('id', $detail->id)->value('state'))->toBe('ready')
        ->and(DB::table('prc_process_runs')->where('id', $runs[0]->value)->value('cancel_requested_at'))->not->toBeNull()
        ->and(DB::table('prc_process_runs')->where('id', $runs[1]->value)->value('cancel_requested_at'))->toBeNull()
        ->and(DB::table('prc_process_run_work')->where('run_id', $runs[1]->value)->where('work_id', $detail->id)->value('accounting_role'))->toBe('required');
});

test('T17 profile edits after preparation cannot allocate a stale manual run', function (): void {
    $profile = EisCrawlerFactory::new()->create();
    $launch = producerLaunch($profile, AcquisitionKind::Fresh);
    $profile->update(['name' => 'changed after prepare']);
    expect(fn () => app(AcquisitionScheduler::class)->manual($launch, ListingStoreRepository::id('stale-profile'), null))
        ->toThrow(InvalidArgumentException::class, 'changed during preparation');
    expect(DB::table('prc_process_runs')->count())->toBe(0);
});

test('T18 отказ mandatory journal откатывает run, work и перенос next_due', function (): void {
    $profile = EisCrawlerFactory::new()->create();
    $producer = app(AcquisitionScheduler::class);
    $launch = producerLaunch($profile, AcquisitionKind::Fresh);
    $producer->configure($profile->id, AcquisitionKind::Fresh, '* * * * *', 'UTC', 1);
    DB::update("UPDATE prc_acquisition_schedules SET next_due_at=clock_timestamp()-interval '1 day'");
    $before = DB::table('prc_acquisition_schedules')->value('next_due_at');
    DB::unprepared("CREATE FUNCTION pg_temp.p218_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'p218 journal failure'; END $$");
    DB::unprepared('CREATE TRIGGER p218_fail BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION pg_temp.p218_fail()');
    try {
        expect(fn () => $producer->due($launch))->toThrow(QueryException::class, 'p218 journal failure');
        expect(DB::table('prc_process_runs')->count())->toBe(0)
            ->and(DB::table('prc_crawl_blocks')->count())->toBe(0)
            ->and(DB::table('prc_process_work')->count())->toBe(0)
            ->and(DB::table('prc_acquisition_schedules')->value('next_due_at'))->toBe($before);
    } finally {
        DB::unprepared('DROP TRIGGER p218_fail ON prc_process_transitions');
    }
    expect($producer->due($launch))->not->toBeNull()->and($producer->due($launch))->toBeNull()
        ->and(DB::table('prc_process_runs')->count())->toBe(1);
});

test('T17 устаревшая версия профиля не выделяет работу и не переносит next_due', function (): void {
    $profile = EisCrawlerFactory::new()->create();
    $producer = app(AcquisitionScheduler::class);
    $producer->configure($profile->id, AcquisitionKind::Fresh, '* * * * *', 'UTC', 2);
    DB::update("UPDATE prc_acquisition_schedules SET next_due_at=clock_timestamp()-interval '1 day'");
    $before = DB::table('prc_acquisition_schedules')->value('next_due_at');
    expect($producer->due(producerLaunch($profile, AcquisitionKind::Fresh)))->toBeNull()
        ->and(DB::table('prc_process_runs')->count())->toBe(0)
        ->and(DB::table('prc_acquisition_schedules')->value('next_due_at'))->toBe($before);
    expect(fn () => $producer->configure($profile->id, AcquisitionKind::Fresh, 'invalid', 'UTC', 3))
        ->toThrow(InvalidArgumentException::class);
});

test('T17 повтор конфигурации сохраняет due, конфликт той же версии отклоняется', function (): void {
    $profile = EisCrawlerFactory::new()->create();
    $producer = app(AcquisitionScheduler::class);
    $producer->configure($profile->id, AcquisitionKind::Fresh, '* * * * *', 'UTC', 2);
    DB::update("UPDATE prc_acquisition_schedules SET next_due_at='2026-01-01 00:00:00+00'");
    $producer->configure($profile->id, AcquisitionKind::Fresh, '* * * * *', 'UTC', 2);
    $producer->configure($profile->id, AcquisitionKind::Fresh, '0 * * * *', 'Europe/Moscow', 1);
    $before = DB::table('prc_acquisition_schedules')->first();
    expect(fn () => $producer->configure($profile->id, AcquisitionKind::Fresh, '0 * * * *', 'UTC', 2))
        ->toThrow(InvalidArgumentException::class, 'Conflicting schedule');
    expect(DB::table('prc_acquisition_schedules')->first())->toEqual($before);
    $producer->configure($profile->id, AcquisitionKind::Fresh, '0 * * * *', 'Europe/Moscow', 3);
    expect(DB::table('prc_acquisition_schedules')->value('profile_version'))->toBe(3)
        ->and(DB::table('prc_acquisition_schedules')->value('timezone'))->toBe('Europe/Moscow');
});

test('T17 manual replay retains resolved defaults but rejects changed explicit bounds', function (): void {
    $profile = EisCrawlerFactory::new()->create();
    $base = producerLaunch($profile, AcquisitionKind::Fresh, empty: true);
    $configuration = ['profile_version' => 1, 'route_profile_id' => ListingStoreRepository::id('route')->value,
        'route_policy_version' => 1, 'kind' => 'fresh', 'initial_from' => '2026-01-01T00:00:00+00:00',
        'through' => '2026-01-02T00:00:00+00:00', 'requested_from' => null, 'requested_through' => null];
    $launch = static fn (array $fields): AcquisitionLaunch => new AcquisitionLaunch($base->profileId,
        $base->profileVersion, $base->platformId, $base->registryId, $base->registry, $base->kind,
        $base->adapterVersion, $base->filter, $base->accessPolicyHash, $base->streamKey, $base->plan,
        new VersionedPayload(json_encode($fields, JSON_THROW_ON_ERROR), 2));
    $scheduler = app(AcquisitionScheduler::class);
    $command = ListingStoreRepository::id('manual-resolved-window');
    $first = $scheduler->manual($launch($configuration), $command, null);
    $later = [...$configuration, 'through' => '2026-01-03T00:00:00+00:00'];
    expect($scheduler->manual($launch($later), $command, null)->value)->toBe($first->value);
    $stored = json_decode(DB::table('prc_process_runs')->where('id', $first->value)->value('configuration_snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($stored['through'])->toBe($configuration['through']);
    expect(fn () => $scheduler->manual($launch([...$later, 'requested_through' => $later['through']]), $command, null))
        ->toThrow(InvalidArgumentException::class, 'another immutable acquisition request');
    expect(DB::table('prc_process_runs')->count())->toBe(1);
});

test('T17 acquisition configuration rejects unknown keys malformed dates and invalid versions', function (): void {
    $valid = ['profile_version' => 1, 'route_profile_id' => ListingStoreRepository::id('route')->value,
        'route_policy_version' => 1, 'kind' => 'fresh', 'initial_from' => '2026-01-01T00:00:00+00:00',
        'through' => '2026-01-02T00:00:00+00:00', 'requested_from' => null, 'requested_through' => null];
    foreach ([['extra' => true], ['through' => '2026-02-31T00:00:00+00:00'], ['profile_version' => 0],
        ['route_policy_version' => '1'], ['requested_from' => '2027-01-01T00:00:00+00:00', 'requested_through' => $valid['through']]] as $invalid) {
        expect(fn () => AcquisitionConfiguration::decode(
            new VersionedPayload(json_encode([...$valid, ...$invalid], JSON_THROW_ON_ERROR), 2)))
            ->toThrow(InvalidArgumentException::class);
    }
});
