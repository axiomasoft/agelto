<?php

declare(strict_types=1);

use App\Actions\Procurement\Crawler\StoreRawFetchArtifactAction;
use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\DeferralReason;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\RawArtifact;
use App\Models\Procurement\Platform\Registry;
use App\Services\PartitionCoverage;
use App\Services\Platforms\Eis\Card\DocumentsParser;
use App\Services\Platforms\Eis\EisSourceClock;
use App\Services\Procurement\Acquisition\Detail\IdentityResolver;
use App\Services\Procurement\Acquisition\Detail\SnapshotFinalizer;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionArtifacts;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionScheduler;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionTick;
use App\Services\Procurement\Process\Daemons\RelayDaemon;
use App\Services\Procurement\Process\Execution\QueueWorkWakePublisher;
use App\Services\Procurement\Process\Execution\StageQueueMap;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Proxyma\Transport\CurlTransferState;
use App\Support\SecretRedactor;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Proxyma\Domain\Execution\ResponseProfile;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

require_once dirname(__DIR__, 2).'/Support/ProcessCore/ProxymaReplay/ResponseFixtures.php';

beforeEach(function (): void {
    Storage::fake('s3');
    $this->realQueue = Queue::getFacadeRoot();
    Queue::fake();
});

test('typed document parser accepts a known empty container and rejects an unrecognized page', function (): void {
    $parser = app(DocumentsParser::class);
    expect($parser->parse('<div class="blockFilesTabDocs"></div>', strict: true)->authorizesReconciliation())->toBeTrue()
        ->and($parser->parse('<html>maintenance</html>', strict: true)->authorizesReconciliation())->toBeFalse();
});

test('Process detail admission visits a small platform cohort before consuming the large cohort backlog', function (): void {
    ExecutionFixtures::authority([StageCode::Detail], lanes: [
        'acquisition' => ['max_outstanding' => 2, 'max_running' => 1, 'low_watermark' => 0, 'refill_max' => 2],
        'acquisition.detail' => ['max_outstanding' => 2, 'max_running' => 1, 'low_watermark' => 0, 'refill_max' => 2],
    ]);
    DB::transaction(function () use (&$small): void {
        for ($i = 0; $i < 20; $i++) {
            ExecutionFixtures::detailWork('platform:large');
        }
        $small = ExecutionFixtures::detailWork('platform:small');
    });
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(2)
        ->and(ExecutionFixtures::work($small)->state)->toBe('admitted')
        ->and(DB::table('prc_process_work')->where('cohort_key', 'platform:large')->where('state', 'admitted')->count())->toBe(1);
});

function auditDaySnapshot(Registry $registry, string $id, string $day, string $title, EisSourceClock $clock): ProcurementSnapshotData
{
    $start = $clock->parseDateTime('!d.m.Y', $day);

    return new ProcurementSnapshotData($registry->id,
        app(IdentityResolver::class)->forRegistry($registry->id, $id)->source,
        $title, 'Подача заявок', Carbon::parse('2026-01-01'), $start,
        sourceUpdatedAtHasDayPrecision: true, sourceUpdatedBefore: $clock->endOfSourceDay($start), initialPrice: '1250000.50');
}

test('поздний ingest старого дневного наблюдения не откатывает более поздний HTTP снимок', function (): void {
    $registry = EisCrawlerFactory::new()->create()->registry;
    $clock = new EisSourceClock('Europe/Berlin');
    $old = auditDaySnapshot($registry, '0173200001234000999', '25.10.2026', 'Старое название', $clock);
    $new = auditDaySnapshot($registry, '0173200001234000999', '25.10.2026', 'Новое название', $clock);
    $head = app(SnapshotFinalizer::class)->ingest($new, observedAt: Carbon::parse('2026-10-25 12:00:00.200000 UTC'));
    app(SnapshotFinalizer::class)->ingest($old, observedAt: Carbon::parse('2026-10-25 12:00:00.100000 UTC'));
    expect($head->refresh()->title)->toBe('Новое название')->and($head->source_updated_has_day_precision)->toBeTrue()
        ->and($head->source_order_ambiguous)->toBeTrue()->and($head->source_updated_before->toIso8601String())->toBe('2026-10-25T23:00:00+00:00')
        ->and($head->revision_count)->toBe(1);
});

test('более новый source day сохраняет время собственного HTTP снимка', function (): void {
    $registry = EisCrawlerFactory::new()->create()->registry;
    $clock = new EisSourceClock;
    $ingest = app(SnapshotFinalizer::class);
    $head = $ingest->ingest(auditDaySnapshot($registry, '0173200001234000988', '25.10.2026', 'Исходная редакция', $clock),
        observedAt: Carbon::parse('2026-10-26 12:00:00.300000 UTC'));
    $ingest->ingest(auditDaySnapshot($registry, '0173200001234000988', '26.10.2026', 'Новая редакция', $clock),
        observedAt: Carbon::parse('2026-10-26 12:00:00.100000 UTC'));
    expect($head->refresh()->source_observed_at->format('u'))->toBe('100000');
    $ingest->ingest(auditDaySnapshot($registry, '0173200001234000988', '26.10.2026', 'Ещё новее', $clock),
        observedAt: Carbon::parse('2026-10-26 12:00:00.200000 UTC'));
    expect($head->refresh()->title)->toBe('Ещё новее');
});

test('Process producer invalid profiles cannot starve later due profiles or multiply one due allocation', function (): void {
    ListingFixtures::bind();
    $bad = [];
    foreach (range(1, 12) as $unused) {
        $profile = AcquisitionProfileFixtures::create();
        $profile->update(['filter' => new EisSearchFilter(okpd2Codes: ['99'])]);
        $bad[] = $profile->id;
    }
    $valid = AcquisitionProfileFixtures::create();
    $scheduler = app(AcquisitionScheduler::class);
    foreach (Crawler::query()->get() as $profile) {
        $scheduler->configure($profile->id, $profile->listing_kind, '* * * * *', 'UTC', $profile->profile_version);
    }
    DB::table('prc_acquisition_schedules')->update(['next_due_at' => DB::raw("clock_timestamp()-interval '1 minute'")]);
    app(AcquisitionTick::class)->tick();
    app(AcquisitionTick::class)->tick();
    expect(DB::table('prc_process_runs')->whereIn('profile_id', $bad)->count())->toBe(0)
        ->and(DB::table('prc_process_runs')->where('profile_id', $valid->id)->count())->toBe(1)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('actual Redis refusal leaves a durable Process wake unclaimed and recoverable after queue restoration', function (): void {
    ExecutionFixtures::authority([StageCode::Detail]);
    $work = DB::transaction(fn () => ExecutionFixtures::detailWork());
    app(WorkFeeder::class)->tick('acquisition');
    $original = config('database.redis.default.port');
    config(['database.redis.default.port' => 1, 'database.redis.default.timeout' => 0.1]);
    Redis::purge('default');
    try {
        $publisher = new QueueWorkWakePublisher(app(StageQueueMap::class), $this->realQueue);
        $relay = app()->makeWith(RelayDaemon::class, ['publisher' => $publisher]);
        expect($relay->tick()->errorCode)->toBe('redis_publish_failed');
        $row = DB::table('prc_process_outbox')->where('dedup_key', 'wake:'.$work->value->value.':1')->sole();
        expect($row->claim_token)->toBeNull()->and($row->claim_until)->toBeNull()->and($row->published_at)->toBeNull();
    } finally {
        config(['database.redis.default.port' => $original]);
        Redis::purge('default');
    }
    $publisher = new QueueWorkWakePublisher(app(StageQueueMap::class), Queue::getFacadeRoot());
    $relay = app()->makeWith(RelayDaemon::class, ['publisher' => $publisher]);
    expect($relay->tick()->processed)->toBe(1);
    Queue::assertPushed(ExecuteWorkJob::class, 1);
});

test('Process progress reports a durable wait reason independently from delivery activity', function (): void {
    ExecutionFixtures::authority([StageCode::Detail]);
    FinalizeFixtures::bind(StageCode::Detail);
    $work = DB::transaction(fn () => ExecutionFixtures::detailWork());
    $lease = FinalizeFixtures::running($work);
    app(WorkFinalizer::class)->finish($lease, new StageDeferred(
        DeferralReason::RateLimit, new RetryAt(new DateTimeImmutable('+1 hour'))));
    expect(Artisan::call('process:health-report'))->toBe(0)->and(Artisan::output())->toContain('rate_limit', 'deferred');
});

test('direct raw catalog registration preserves the newest usage of an immutable repeated path', function (): void {
    $store = app(StoreRawFetchArtifactAction::class);
    $path = $store->execute('s3', 'raw/catalog', ['id' => 1], 'same bytes', 'txt');
    $this->travel(2)->hours();
    $store->execute('s3', 'raw/catalog', ['id' => 1], 'same bytes', 'txt');
    $latest = RawArtifact::query()->where('path', $path)->value('last_seen_at');
    $this->travelBack();
    $store->execute('s3', 'raw/catalog', ['id' => 1], 'same bytes', 'txt');
    expect(RawArtifact::query()->where('path', $path)->count())->toBe(1)
        ->and(RawArtifact::query()->where('path', $path)->value('last_seen_at'))->toBe($latest)
        ->and(array_key_exists('crawler:catalog-raw-history', Artisan::all()))->toBeFalse();
});

test('acquisition raw catalog respects independently configured listing and detail disks', function (): void {
    config(['acquisition.raw_disk' => 'listing-disk', 'acquisition.raw_html_disk' => 'detail-disk',
        'acquisition.raw_html_retention_enabled' => true, 'acquisition.raw_html_gzip' => false]);
    Storage::fake('listing-disk');
    Storage::fake('detail-disk');
    $capture = app(AcquisitionArtifacts::class);
    foreach ([TrafficClass::FreshListing, TrafficClass::Detail] as $traffic) {
        $capture->capture(new TransportSucceeded(
            ContractRuntime::response('raw body', 'text/plain'),
            LedgerFixtures::uuid()), $traffic);
    }
    expect(RawArtifact::query()->pluck('disk')->sort()->values()->all())->toBe(['detail-disk', 'listing-disk']);
});

test('распакованное тело ограничено фактическими байтами и поток закрывается после отказа', function (): void {
    $state = new CurlTransferState(new ResponseProfile('raw', 8192, 8192, 8192), responseScope(), 8192);
    $state->writeHeader("Content-Encoding: gzip\r\n");
    $wire = gzencode(str_repeat('x', 8193));
    expect(strlen($wire))->toBeLessThan(8192)
        ->and($state->writeChunk($wire))->toBe(0)
        ->and($state->limit)->toBeTrue();
    $reader = $state->reader ?? throw new LogicException('Response sink was not created.');
    $stream = (new ReflectionProperty($reader, 'stream'))->getValue($reader);
    expect($reader->decodedBytes())->toBe(8193);
    $reader->close();
    expect(is_resource($stream))->toBeFalse();
});

test('граница суток хранит 23 и 25 часов в зоне с DST', function (string $date, int $hours): void {
    $clock = new EisSourceClock('Europe/Berlin');
    $start = $clock->parseDateTime('!d.m.Y', $date);
    expect((int) $start->diffInHours($clock->endOfSourceDay($start)))->toBe($hours);
})->with([['29.03.2026', 23], ['25.10.2026', 25]]);

test('частично покрытый месяц достраивается по границам а имя чужой таблицы не считается покрытием', function (): void {
    DB::statement('CREATE TABLE audit_partition_probe (at timestamptz) PARTITION BY RANGE (at)');
    DB::statement("CREATE TABLE audit_partition_probe_half PARTITION OF audit_partition_probe FOR VALUES FROM ('2026-01-01') TO ('2026-01-15')");
    $coverage = app(PartitionCoverage::class);
    $coverage->ensure('audit_partition_probe', Carbon::parse('2026-01-01 UTC'), Carbon::parse('2026-02-01 UTC'));
    $coverage->ensure('audit_partition_probe', Carbon::parse('2026-01-01 UTC'), Carbon::parse('2026-02-01 UTC'));
    DB::insert("INSERT INTO audit_partition_probe VALUES ('2026-01-31')");
    expect(DB::table('audit_partition_probe')->count())->toBe(1);
    DB::statement('CREATE TABLE audit_partition_probe_2026_02 (id int)');
    expect(fn () => $coverage->ensure('audit_partition_probe', Carbon::parse('2026-02-01 UTC'), Carbon::parse('2026-03-01 UTC')))
        ->toThrow(QueryException::class);
});

test('секреты скрываются в URL SQL и вложенном контексте', function (): void {
    $clean = SecretRedactor::text('https://user:secret@host.test/?token=abc password="xyz" SQL: update proxies set password=private');
    expect($clean)->not->toContain('secret', 'abc', 'xyz', 'private')
        ->and(SecretRedactor::context(['nested' => ['password' => 'x'], 'ok' => 'value']))
        ->toBe(['nested' => ['password' => '[redacted]'], 'ok' => 'value']);
});

test('retention удаляет ограниченную порцию просроченных объектов и оставляет свежие', function (): void {
    $action = app(StoreRawFetchArtifactAction::class);
    $this->travel(-31)->days();
    $old = $action->execute('s3', 'raw/test', ['id' => 1], 'old', 'txt');
    $this->travelBack();
    $fresh = $action->execute('s3', 'raw/test', ['id' => 2], 'fresh', 'txt');
    expect(Artisan::call('process:retention', ['--limit' => 1]))->toBe(0)
        ->and(Schema::hasTable('prc_source_incidents'))->toBeFalse();
    Storage::disk('s3')->assertMissing($old);
    Storage::disk('s3')->assertExists($fresh);
});
