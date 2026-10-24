<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\DeltaScanOutcome;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\Modes\DeltaScanProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;
use Tests\Support\Postgres\ConcurrentTransactions;

/**
 * П-3 (`research/05-change-tracking.md` §3.1): плановый delta-scan окна
 * `[сегодня − window_overlap_days; сегодня]` по дате обновления — переиспользует
 * `ArchiveWindowScanner` тем же способом, что `BacklogRescanProcessor`, профиль резолвится
 * так же (активный archive-профиль реестра).
 *
 * @return array<string, string>
 */
function deltaScanCsvRow(string $registryNumber): array
{
    return [
        'Реестровый номер закупки' => $registryNumber,
        'Наименование закупки' => 'Поставка оргтехники',
        'Этап закупки' => 'Подача заявок',
        'Дата размещения' => '01.03.2026',
        'Дата обновления' => '02.03.2026 10:15:00',
    ];
}

function deltaScanBatch(string $registryNumber): ListingBatch
{
    return new ListingBatch(
        rows: [deltaScanCsvRow($registryNumber)],
        rawBody: "raw csv body for {$registryNumber}",
        dedupSignature: "sig-{$registryNumber}",
        rowsTotal: 1,
        httpStatus: 200,
        durationMs: 5,
        requestParams: [],
    );
}

function useDeltaScanQueueSourceMethod(array $batches): void
{
    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(repeatStableWindows: true, batches: $batches));

    config(['crawler.platforms.eis.source_methods' => [
        SourceMethodEnum::Csv->value => QueueSourceMethod::class,
    ]]);
}

/**
 * Тот же приём, что `ArchiveRunProcessorTest::useArchiveRunStopSignalingSourceMethod()` (P3.7):
 * `QueueSourceMethod` не умеет менять состояние строки прогона В ПРОЦЕССЕ обхода — этот двойник
 * вдобавок к выдаче батча по индексу вызова применяет побочный эффект, симулируя оператора,
 * нажавшего «Остановить»/«Отменить» МЕЖДУ днями. Возвращает сам двойник — тест читает `->calls`.
 *
 * @param  list<ListingBatch>  $batches
 * @param  array<int, Closure>  $sideEffectsByCallIndex  0-based индекс вызова → эффект ПОСЛЕ выдачи батча
 */
function useDeltaScanStopSignalingSourceMethod(array $batches, array $sideEffectsByCallIndex = []): object
{
    $double = new class($batches, $sideEffectsByCallIndex) implements SourceMethod
    {
        public int $calls = 0;

        public function __construct(private readonly array $batches, private readonly array $sideEffects) {}

        public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
        {
            $index = $this->calls;
            $batch = $this->batches[$index] ?? throw new OutOfBoundsException('стоп-двойник: очередь блоков исчерпана раньше, чем ожидал тест.');
            $this->calls++;

            ($this->sideEffects[$index] ?? null)?->__invoke();

            return $batch;
        }

        public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails
        {
            return new ProcurementDetails;
        }

        public function isAvailable(): bool
        {
            return true;
        }
    };

    app()->instance($double::class, $double);
    config(['crawler.platforms.eis.source_methods' => [
        SourceMethodEnum::Csv->value => $double::class,
    ]]);

    return $double;
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00'));
    config([
        'crawler.platforms.eis.block_size' => 2,
        'crawler.archive.price_ceiling' => 1_000_000_000,
        'crawler.tracking.window_overlap_days' => 2,
    ]);
});

test('нет активного archive-профиля реестра — warning, run не создаётся', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);

    Log::spy();

    $result = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);

    expect($result->outcome)->toBe(DeltaScanOutcome::MissingArchiveProfile)
        ->and(Run::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'no active archive profile'))
        ->once();
});

test('чужой running archive-прогон даёт busy без сканирования и completion', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    $archiveCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);
    $ownerRun = Run::factory()->for($archiveCrawler)->create([
        'purpose' => RunPurpose::Profile,
        'subject_key' => null,
    ]);

    $result = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);

    expect($result->outcome)->toBe(DeltaScanOutcome::Busy)
        ->and($ownerRun->refresh()->status)->toBe(RunStatus::Running)
        ->and($ownerRun->purpose)->toBe(RunPurpose::Profile);
});

test('занятый execution lease того же delta owner не входит в scanner и не меняет cursor', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    $archiveCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);
    $run = Run::factory()->for($archiveCrawler)->create([
        'purpose' => RunPurpose::Delta,
        'subject_key' => "fresh-crawler:{$freshCrawler->id}",
        'cursor' => ['blockFrom' => 501],
    ]);
    $owner = Run::query()->findOrFail($run->id);

    expect(app(CrawlerRunManager::class)->acquireExecutionLease(run: $owner))->toBeTrue()
        ->and(app(DeltaScanProcessor::class)->run(crawler: $freshCrawler)->outcome)->toBe(DeltaScanOutcome::Busy);

    expect($run->refresh()->cursor['blockFrom'])->toBe(501)
        ->and($run->status)->toBe(RunStatus::Running);
});

test('второй PostgreSQL delta processor возвращает busy без scanner и не меняет cursor или stats', function () {
    $fixture = ConcurrentTransactions::execute(<<<'PHP'
$registry = \App\Models\Procurement\Platform\Registry::factory()->eis()->create();
$freshCrawler = \App\Models\Procurement\Crawler\Crawler::factory()->create([
    'registry_id' => $registry->id,
    'mode' => \App\Enums\Procurement\Crawler\CrawlerMode::Fresh,
]);
$archiveCrawler = \App\Models\Procurement\Crawler\Crawler::factory()->create([
    'registry_id' => $registry->id,
    'mode' => \App\Enums\Procurement\Crawler\CrawlerMode::Archive,
    'is_active' => true,
]);
$run = \App\Models\Procurement\Crawler\Run::factory()->for($archiveCrawler)->create([
    'purpose' => \App\Enums\Procurement\Crawler\RunPurpose::Delta,
    'subject_key' => "fresh-crawler:{$freshCrawler->id}",
    'cursor' => ['blockFrom' => 501],
    'stats' => ['marker' => 'unchanged'],
]);

return [
    'fresh_crawler_id' => $freshCrawler->id,
    'archive_crawler_id' => $archiveCrawler->id,
    'run_id' => $run->id,
    'registry_id' => $registry->id,
    'platform_id' => $registry->platform_id,
];
PHP);

    try {
        $owner = ConcurrentTransactions::execute(<<<PHP
\$run = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['run_id']});

return [
    'acquired' => app(\App\Services\Procurement\CrawlerRunManager::class)->acquireExecutionLease(run: \$run),
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()'),
];
PHP);
        $second = ConcurrentTransactions::execute(<<<PHP
config(['crawler.platforms.eis.source_methods' => [
    \App\Enums\Procurement\Crawler\SourceMethod::Csv->value => \Tests\Support\Doubles\Crawler\BrokenSourceMethod::class,
]]);
\$crawler = \App\Models\Procurement\Crawler\Crawler::query()->findOrFail({$fixture['fresh_crawler_id']});
\$result = app(\App\Services\Procurement\Modes\DeltaScanProcessor::class)->run(crawler: \$crawler);

return [
    'outcome' => \$result->outcome->value,
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()'),
];
PHP);
        $state = ConcurrentTransactions::execute(<<<PHP
\$run = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['run_id']});

return [
    'cursor' => \$run->cursor->toArray(),
    'stats' => \$run->stats->toArray(),
    'owner_token' => \$run->execution_owner_token,
];
PHP);

        expect($owner['acquired'])->toBeTrue()
            ->and($owner['backend_pid'])->not->toBe($second['backend_pid'])
            ->and($second['outcome'])->toBe(DeltaScanOutcome::Busy->value)
            ->and($state['cursor'])->toBe(['blockFrom' => 501])
            ->and($state['stats'])->toBe(['marker' => 'unchanged'])
            ->and($state['owner_token'])->not->toBeNull();
    } finally {
        ConcurrentTransactions::execute(<<<PHP
\Illuminate\Support\Facades\DB::table('prc_fetches')->where('crawler_run_id', {$fixture['run_id']})->delete();
\App\Models\Procurement\Crawler\Run::query()->whereKey({$fixture['run_id']})->delete();
\App\Models\Procurement\Crawler\Crawler::query()->whereIn('id', [{$fixture['fresh_crawler_id']}, {$fixture['archive_crawler_id']}])->delete();
\App\Models\Procurement\Platform\Registry::query()->whereKey({$fixture['registry_id']})->delete();
\App\Models\Procurement\Platform\Platform::query()->whereKey({$fixture['platform_id']})->delete();

return ['cleaned' => true];
PHP);
    }
});

test('delta processor после expiry продолжает точный checkpoint прежнего owner', function () {
    $fixture = ConcurrentTransactions::execute(<<<'PHP'
$registry = \App\Models\Procurement\Platform\Registry::factory()->eis()->create();
$freshCrawler = \App\Models\Procurement\Crawler\Crawler::factory()
    ->filter(new \App\Dto\Platforms\Eis\EisSearchFilter(stageApplications: true, okpd2Codes: ['43']))
    ->create([
        'registry_id' => $registry->id,
        'mode' => \App\Enums\Procurement\Crawler\CrawlerMode::Fresh,
    ]);
$archiveCrawler = \App\Models\Procurement\Crawler\Crawler::factory()->create([
    'registry_id' => $registry->id,
    'mode' => \App\Enums\Procurement\Crawler\CrawlerMode::Archive,
    'is_active' => true,
]);
$run = \App\Models\Procurement\Crawler\Run::factory()->for($archiveCrawler)->create([
    'purpose' => \App\Enums\Procurement\Crawler\RunPurpose::Delta,
    'subject_key' => "fresh-crawler:{$freshCrawler->id}",
    'cursor' => (new \App\Dto\Procurement\Crawler\ListingCursor(
        blockFrom: 501,
        day: '2026-09-01',
        priceStack: ['0:100'],
        dateField: 'update',
        recoveryScope: \App\Dto\Procurement\Crawler\RecoveryScope::fromFreshCrawler($freshCrawler)->toArray(),
    ))->toArray(),
]);

return [
    'fresh_crawler_id' => $freshCrawler->id,
    'archive_crawler_id' => $archiveCrawler->id,
    'run_id' => $run->id,
    'registry_id' => $registry->id,
    'platform_id' => $registry->platform_id,
];
PHP);

    try {
        $owner = ConcurrentTransactions::execute(<<<PHP
\$run = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['run_id']});

return [
    'acquired' => app(\App\Services\Procurement\CrawlerRunManager::class)->acquireExecutionLease(run: \$run),
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()'),
];
PHP);
        ConcurrentTransactions::execute(<<<PHP
\App\Models\Procurement\Crawler\Run::query()->whereKey({$fixture['run_id']})->update([
    'execution_lease_expires_at' => \Illuminate\Support\Facades\DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 second'"),
]);

return ['expired' => true];
PHP);
        $takeover = ConcurrentTransactions::execute(<<<PHP
\Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-09-01 12:00:00'));
config([
    'crawler.platforms.eis.block_size' => 2,
    'crawler.archive.price_ceiling' => 100,
    'crawler.archive.min_price_interval' => 10,
    'crawler.archive.max_blocks_per_window' => 50,
    'crawler.tracking.window_overlap_days' => 0,
]);
\$source = new class implements \App\Services\Procurement\Contracts\SourceMethod
{
    public int \$calls = 0;

    public function collectListing(\App\Models\Procurement\Crawler\Crawler \$crawler, \App\Dto\Procurement\Crawler\ListingCursor \$cursor): \App\Dto\Procurement\Crawler\ListingBatch
    {
        if (\$cursor->day !== '2026-09-01' || \$cursor->blockFrom !== (\$this->calls === 0 ? 501 : 1) || \$cursor->priceStack !== ['0:100']) {
            throw new \RuntimeException('Scanner did not resume the durable delta checkpoint.');
        }

        \$this->calls++;

        return new \App\Dto\Procurement\Crawler\ListingBatch(rows: [], rawBody: '', dedupSignature: null, rowsTotal: 0, httpStatus: 200, durationMs: 0, requestParams: []);
    }

    public function collectDetails(string \$registryNumber, \App\Models\Procurement\Platform\Registry \$registry): \App\Dto\Procurement\ProcurementDetails
    {
        return new \App\Dto\Procurement\ProcurementDetails;
    }

    public function isAvailable(): bool
    {
        return true;
    }
};
app()->instance(\$source::class, \$source);
config(['crawler.platforms.eis.source_methods' => [
    \App\Enums\Procurement\Crawler\SourceMethod::Csv->value => \$source::class,
]]);
\$crawler = \App\Models\Procurement\Crawler\Crawler::query()->findOrFail({$fixture['fresh_crawler_id']});
\$result = app(\App\Services\Procurement\Modes\DeltaScanProcessor::class)->run(crawler: \$crawler);

return [
    'outcome' => \$result->outcome->value,
    'calls' => \$source->calls,
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()'),
];
PHP);
        $state = ConcurrentTransactions::execute(<<<PHP
\$run = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['run_id']});

return [
    'status' => \$run->status->value,
    'cursor' => \$run->cursor->toArray(),
];
PHP);

        expect($owner['acquired'])->toBeTrue()
            ->and($owner['backend_pid'])->not->toBe($takeover['backend_pid'])
            ->and($takeover['outcome'])->toBe(DeltaScanOutcome::Processed->value)
            ->and($takeover['calls'])->toBe(2)
            ->and($state['status'])->toBe(RunStatus::Completed->value)
            ->and($state['cursor']['day'])->toBe('2026-09-02');
    } finally {
        ConcurrentTransactions::execute(<<<PHP
\Illuminate\Support\Facades\DB::table('prc_fetches')->where('crawler_run_id', {$fixture['run_id']})->delete();
\App\Models\Procurement\Crawler\Run::query()->whereKey({$fixture['run_id']})->delete();
\App\Models\Procurement\Crawler\Crawler::query()->whereIn('id', [{$fixture['fresh_crawler_id']}, {$fixture['archive_crawler_id']}])->delete();
\App\Models\Procurement\Platform\Registry::query()->whereKey({$fixture['registry_id']})->delete();
\App\Models\Procurement\Platform\Platform::query()->whereKey({$fixture['platform_id']})->delete();

return ['cleaned' => true];
PHP);
    }
});

test('активный archive-профиль реестра — дренирует окно [сегодня − overlap; сегодня] по update, run завершён', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()
        ->filter(new EisSearchFilter(stageApplications: true, okpd2Codes: ['43'], priceTo: 1_000))
        ->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    $archiveCrawler = Crawler::factory()
        ->filter(new EisSearchFilter(stageCompleted: true, okpd2Codes: ['99'], priceTo: 5_000))
        ->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    // window_overlap_days=2, "сегодня"=2026-08-05 → окно [2026-08-03; 2026-08-05] — 3 дня.
    useDeltaScanQueueSourceMethod([
        deltaScanBatch('p46-delta-day-1'),
        deltaScanBatch('p46-delta-day-2'),
        deltaScanBatch('p46-delta-day-3'),
    ]);

    $result = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);
    $run = $result->run;

    expect($result->outcome)->toBe(DeltaScanOutcome::Processed)
        ->and($run)->not->toBeNull()
        ->and($run->crawler_id)->toBe($archiveCrawler->id)
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and($run->cursor['recoveryScope']['freshCrawlerId'])->toBe($freshCrawler->id)
        ->and($run->cursor['recoveryScope']['filter']['stageApplications'])->toBeTrue()
        ->and($run->cursor['recoveryScope']['filter']['stageCompleted'])->toBeFalse()
        ->and($run->cursor['recoveryScope']['filter']['okpd2Codes'])->toBe(['43'])
        ->and(Procurement::query()->count())->toBe(3);
});

test('стоп-сигнал Pause между днями останавливает delta-scan целиком, следующий день не обходится', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    $archiveCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    // window_overlap_days=2, "сегодня"=2026-08-05 → окно [2026-08-03; 2026-08-05] — 3 дня.
    $sourceMethod = useDeltaScanStopSignalingSourceMethod(
        batches: [
            deltaScanBatch('p47-delta-day-1'),
            deltaScanBatch('p47-delta-day-3-must-not-be-fetched'),
        ],
        sideEffectsByCallIndex: [
            0 => fn () => Run::query()->where('crawler_id', $archiveCrawler->id)->update([
                'stop_requested_at' => Carbon::now(),
                'stop_kind' => RunStopKind::Pause->value,
            ]),
        ],
    );

    $result = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);
    $run = $result->run;

    expect($sourceMethod->calls)->toBe(1)
        ->and($result->outcome)->toBe(DeltaScanOutcome::Processed)
        ->and($run?->fresh()->status)->toBe(RunStatus::Paused)
        ->and($run?->fresh()->status)->not->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(1);
});

test('стоп-сигнал Cancel между днями останавливает delta-scan целиком, статус терминален', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    $archiveCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    $sourceMethod = useDeltaScanStopSignalingSourceMethod(
        batches: [
            deltaScanBatch('p47-delta-day-1-cancel'),
            deltaScanBatch('p47-delta-day-3-must-not-be-fetched'),
        ],
        sideEffectsByCallIndex: [
            0 => fn () => Run::query()->where('crawler_id', $archiveCrawler->id)->update([
                'stop_requested_at' => Carbon::now(),
                'stop_kind' => RunStopKind::Cancel->value,
            ]),
        ],
    );

    $result = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);
    $run = $result->run;

    expect($sourceMethod->calls)->toBe(1)
        ->and($result->outcome)->toBe(DeltaScanOutcome::Processed)
        ->and($run?->fresh()->status)->toBe(RunStatus::Cancelled)
        ->and($run?->fresh()->status)->not->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(1);
});
