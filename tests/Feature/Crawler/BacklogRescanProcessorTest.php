<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\RecoveryScope;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Enums\Procurement\Crawler\SourceStageStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\Modes\BacklogRescanProcessor;
use App\Services\Procurement\Modes\FreshRunProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;
use Tests\Support\Postgres\ConcurrentTransactions;

/**
 * Один «частичный» блок (`rowsTotal < block_size=2`) — сразу дренирует update-окно
 * за один день, без ценового дробления.
 */
function backlogRescanDrainedBatch(): ListingBatch
{
    return new ListingBatch(
        rows: [],
        rawBody: '',
        dedupSignature: null,
        rowsTotal: 0,
        httpStatus: 200,
        durationMs: 0,
        requestParams: [],
    );
}

/** @return array<string, mixed> */
function backlogFreshCursor(Crawler $crawler): array
{
    return (new ListingCursor(
        recoveryScope: RecoveryScope::fromFreshCrawler(crawler: $crawler)->toArray(),
    ))->toArray();
}

/** @return array<string, string> */
function backlogFreshRow(string $registryNumber): array
{
    return [
        'Реестровый номер закупки' => $registryNumber,
        'Способ определения поставщика' => 'Электронный аукцион',
        'Наименование закупки' => 'Тестовая закупка',
        'Этап закупки' => 'Подача заявок',
        'Дата размещения' => '01.01.2024',
        'Дата обновления' => '01.01.2024 12:00:00',
    ];
}

/** @param list<array<string, string>> $rows */
function backlogFreshBatch(array $rows, string $signature): ListingBatch
{
    return new ListingBatch(
        rows: $rows,
        rawBody: 'fresh cap fixture',
        dedupSignature: $signature,
        rowsTotal: count($rows),
        httpStatus: 200,
        durationMs: 0,
        requestParams: ['from' => '1', 'to' => '2'],
    );
}

function useBacklogRescanQueueSourceMethod(array $batches): void
{
    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: $batches));

    config(['crawler.platforms.eis.source_methods' => [
        SourceMethodEnum::Csv->value => QueueSourceMethod::class,
    ]]);
}

/**
 * Тот же приём, что `ArchiveRunProcessorTest::useArchiveRunStopSignalingSourceMethod()` (P3.7):
 * `QueueSourceMethod` не умеет менять состояние строки прогона В ПРОЦЕССЕ обхода — этот двойник
 * вдобавок к выдаче батча по индексу вызова применяет побочный эффект, симулируя оператора,
 * нажавшего «Остановить»/«Отменить» МЕЖДУ днями рескана. Возвращает сам двойник — тест читает
 * `->calls`.
 *
 * @param  list<ListingBatch>  $batches
 * @param  array<int, Closure>  $sideEffectsByCallIndex  0-based индекс вызова → эффект ПОСЛЕ выдачи батча
 */
function useBacklogRescanStopSignalingSourceMethod(array $batches, array $sideEffectsByCallIndex = []): object
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
    config([
        'crawler.eis.block_size' => 2,
        'crawler.archive.price_ceiling' => 100,
        'crawler.archive.min_price_interval' => 10,
        'crawler.archive.max_blocks_per_window' => 50,
        // Нулевой оверлап здесь — тесты этого файла проверяют механику sweep()/архив-профиля,
        // не окно перекрытия (П-2, отдельная проба — тест ниже про сдвиг окна).
        'crawler.tracking.window_overlap_days' => 0,
    ]);
});

test('cap_hit с живым archive-профилем реестра — scan вызван по update-окну, флаг помечен', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()
        ->filter(new EisSearchFilter(stageApplications: true, okpd2Codes: ['43'], priceTo: 1_000))
        ->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()
        ->filter(new EisSearchFilter(stageCompleted: true, okpd2Codes: ['99'], priceTo: 5_000))
        ->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'cursor' => backlogFreshCursor(crawler: $freshCrawler),
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z']],
    ]);

    useBacklogRescanQueueSourceMethod([backlogRescanDrainedBatch()]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(1);

    $archiveRun = Run::query()->where('crawler_id', '!=', $freshCrawler->id)->sole();
    expect($archiveRun->status)->toBe(RunStatus::Completed)
        ->and($archiveRun->cursor['recoveryScope']['freshCrawlerId'])->toBe($freshCrawler->id)
        ->and($archiveRun->cursor['recoveryScope']['filter']['stageApplications'])->toBeTrue()
        ->and($archiveRun->cursor['recoveryScope']['filter']['stageCompleted'])->toBeFalse()
        ->and($archiveRun->cursor['recoveryScope']['filter']['okpd2Codes'])->toBe(['43']);

    $freshRun->refresh();
    expect($freshRun->stats['backlog_rescanned_at'])->not->toBeNull();
});

test('backlog использует scope из fresh-run, если профиль изменили после cap_hit', function () {
    $registry = Registry::factory()->eis()->create();
    $originalFilter = new EisSearchFilter(stageApplications: true, okpd2Codes: ['43'], priceTo: 1_000);
    $freshCrawler = Crawler::factory()
        ->filter($originalFilter)
        ->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create([
        'registry_id' => $registry->id,
        'mode' => CrawlerMode::Archive,
        'is_active' => true,
    ]);

    $rows = [
        backlogFreshRow('0173200001234000101'),
        backlogFreshRow('0173200001234000102'),
    ];
    useBacklogRescanQueueSourceMethod([
        backlogFreshBatch(rows: $rows, signature: 'capped-scope'),
        backlogFreshBatch(rows: $rows, signature: 'capped-scope'),
    ]);

    $freshRun = app(FreshRunProcessor::class)->run(crawler: $freshCrawler);

    expect($freshRun->stats['cap_hit'] ?? null)->toBeTrue()
        ->and($freshRun->cursor['recoveryScope']['filter'])->toEqual($originalFilter->toArray());

    $freshCrawler->update(['filter' => new EisSearchFilter(
        stageCompleted: true,
        okpd2Codes: ['99'],
        priceTo: 5_000,
    )]);

    $sourceMethod = new class implements SourceMethod
    {
        /** @var array<string, mixed>|null */
        public ?array $capturedFilter = null;

        public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
        {
            $filter = $crawler->filter;

            if (! $filter instanceof EisSearchFilter) {
                throw new RuntimeException('Backlog scanner lost the typed EIS filter.');
            }

            $this->capturedFilter = $filter->toArray();

            return backlogRescanDrainedBatch();
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
    app()->instance($sourceMethod::class, $sourceMethod);
    config(['crawler.platforms.eis.source_methods' => [
        SourceMethodEnum::Csv->value => $sourceMethod::class,
    ]]);

    expect(app(BacklogRescanProcessor::class)->sweep())->toBe(1)
        ->and($sourceMethod->capturedFilter)->toEqual($originalFilter->toArray())
        ->and($freshRun->refresh()->stats['backlog_rescanned_at'] ?? null)->not->toBeNull();
});

test('повторный sweep() уже помеченный run не берёт', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'stats' => [
            'cap_hit' => true,
            'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z'],
            'backlog_rescanned_at' => '2024-01-02T00:00:00Z',
        ],
    ]);

    useBacklogRescanQueueSourceMethod([]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0)
        ->and(Run::query()->count())->toBe(1);
});

test('чужой running archive-прогон оставляет fresh-run без backlog acknowledgement', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    $archiveCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);
    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'cursor' => backlogFreshCursor(crawler: $freshCrawler),
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z']],
    ]);
    $ownerRun = Run::factory()->for($archiveCrawler)->create([
        'purpose' => RunPurpose::Profile,
        'subject_key' => null,
    ]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0)
        ->and($ownerRun->refresh()->status)->toBe(RunStatus::Running)
        ->and($freshRun->refresh()->stats['backlog_rescanned_at'] ?? null)->toBeNull();
});

test('занятый execution lease того же backlog owner не входит в scanner и не помечает fresh-run', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    $archiveCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);
    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'cursor' => backlogFreshCursor(crawler: $freshCrawler),
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z']],
    ]);
    $run = Run::factory()->for($archiveCrawler)->create([
        'purpose' => RunPurpose::Backlog,
        'subject_key' => "fresh-run:{$freshRun->id}",
        'cursor' => ['blockFrom' => 501],
    ]);
    $owner = Run::query()->findOrFail($run->id);

    expect(app(CrawlerRunManager::class)->acquireExecutionLease(run: $owner))->toBeTrue()
        ->and(app(BacklogRescanProcessor::class)->sweep())->toBe(0);

    expect($run->refresh()->cursor['blockFrom'])->toBe(501)
        ->and($run->status)->toBe(RunStatus::Running)
        ->and($freshRun->refresh()->stats['backlog_rescanned_at'] ?? null)->toBeNull();
});

test('второй PostgreSQL backlog processor возвращает busy без scanner и не меняет cursor или stats', function () {
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
$freshRun = \App\Models\Procurement\Crawler\Run::factory()->for($freshCrawler, 'crawler')->create([
    'status' => \App\Enums\Procurement\Crawler\RunStatus::Completed,
    'cursor' => (new \App\Dto\Procurement\Crawler\ListingCursor(
        recoveryScope: \App\Dto\Procurement\Crawler\RecoveryScope::fromFreshCrawler($freshCrawler)->toArray(),
    ))->toArray(),
    'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2026-09-01', 'to' => '2026-09-01']],
]);
$archiveRun = \App\Models\Procurement\Crawler\Run::factory()->for($archiveCrawler)->create([
    'purpose' => \App\Enums\Procurement\Crawler\RunPurpose::Backlog,
    'subject_key' => "fresh-run:{$freshRun->id}",
    'cursor' => ['blockFrom' => 501],
    'stats' => ['marker' => 'unchanged'],
]);

return [
    'fresh_run_id' => $freshRun->id,
    'archive_run_id' => $archiveRun->id,
    'crawler_id' => $freshCrawler->id,
    'archive_crawler_id' => $archiveCrawler->id,
    'registry_id' => $registry->id,
    'platform_id' => $registry->platform_id,
];
PHP);

    try {
        $owner = ConcurrentTransactions::execute(<<<PHP
\$run = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['archive_run_id']});

return [
    'acquired' => app(\App\Services\Procurement\CrawlerRunManager::class)->acquireExecutionLease(run: \$run),
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()'),
];
PHP);
        $second = ConcurrentTransactions::execute(<<<PHP
config(['crawler.platforms.eis.source_methods' => [
    \App\Enums\Procurement\Crawler\SourceMethod::Csv->value => \Tests\Support\Doubles\Crawler\BrokenSourceMethod::class,
]]);

return [
    'processed' => app(\App\Services\Procurement\Modes\BacklogRescanProcessor::class)->sweep(),
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()'),
];
PHP);
        $state = ConcurrentTransactions::execute(<<<PHP
\$archive = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['archive_run_id']});
\$fresh = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['fresh_run_id']});

return [
    'cursor' => \$archive->cursor->toArray(),
    'stats' => \$archive->stats->toArray(),
    'fresh_stats' => \$fresh->stats->toArray(),
    'owner_token' => \$archive->execution_owner_token,
];
PHP);

        expect($owner['acquired'])->toBeTrue()
            ->and($owner['backend_pid'])->not->toBe($second['backend_pid'])
            ->and($second['processed'])->toBe(0)
            ->and($state['cursor'])->toBe(['blockFrom' => 501])
            ->and($state['stats'])->toBe(['marker' => 'unchanged'])
            ->and($state['fresh_stats']['cap_hit'])->toBeTrue()
            ->and($state['fresh_stats']['possible_skip_window']['from'])->toBe('2026-09-01')
            ->and($state['fresh_stats']['possible_skip_window']['to'])->toBe('2026-09-01')
            ->and($state['owner_token'])->not->toBeNull();
    } finally {
        ConcurrentTransactions::execute(<<<PHP
\App\Models\Procurement\Crawler\Run::query()->whereIn('id', [{$fixture['fresh_run_id']}, {$fixture['archive_run_id']}])->delete();
\App\Models\Procurement\Crawler\Crawler::query()->whereIn('id', [{$fixture['crawler_id']}, {$fixture['archive_crawler_id']}])->delete();
\App\Models\Procurement\Platform\Registry::query()->whereKey({$fixture['registry_id']})->delete();
\App\Models\Procurement\Platform\Platform::query()->whereKey({$fixture['platform_id']})->delete();

return ['cleaned' => true];
PHP);
    }
});

test('backlog processor после expiry продолжает точный checkpoint прежнего owner', function () {
    $fixture = ConcurrentTransactions::execute(<<<'PHP'
$registry = \App\Models\Procurement\Platform\Registry::factory()->eis()->create();
$freshCrawler = \App\Models\Procurement\Crawler\Crawler::factory()
    ->filter(new \App\Dto\Procurement\Crawler\EisSearchFilter(stageApplications: true, okpd2Codes: ['43']))
    ->create([
        'registry_id' => $registry->id,
        'mode' => \App\Enums\Procurement\Crawler\CrawlerMode::Fresh,
    ]);
$archiveCrawler = \App\Models\Procurement\Crawler\Crawler::factory()->create([
    'registry_id' => $registry->id,
    'mode' => \App\Enums\Procurement\Crawler\CrawlerMode::Archive,
    'is_active' => true,
]);
$freshRun = \App\Models\Procurement\Crawler\Run::factory()->for($freshCrawler, 'crawler')->create([
    'status' => \App\Enums\Procurement\Crawler\RunStatus::Completed,
    'cursor' => (new \App\Dto\Procurement\Crawler\ListingCursor(
        recoveryScope: \App\Dto\Procurement\Crawler\RecoveryScope::fromFreshCrawler($freshCrawler)->toArray(),
    ))->toArray(),
    'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2026-09-01', 'to' => '2026-09-01']],
]);
$archiveRun = \App\Models\Procurement\Crawler\Run::factory()->for($archiveCrawler)->create([
    'purpose' => \App\Enums\Procurement\Crawler\RunPurpose::Backlog,
    'subject_key' => "fresh-run:{$freshRun->id}",
    'cursor' => (new \App\Dto\Procurement\Crawler\ListingCursor(
        blockFrom: 501,
        day: '2026-09-01',
        priceStack: ['0:100'],
        dateField: 'update',
        recoveryScope: \App\Dto\Procurement\Crawler\RecoveryScope::fromFreshCrawler($freshCrawler)->toArray(),
    ))->toArray(),
]);

return [
    'fresh_run_id' => $freshRun->id,
    'archive_run_id' => $archiveRun->id,
    'fresh_crawler_id' => $freshCrawler->id,
    'archive_crawler_id' => $archiveCrawler->id,
    'registry_id' => $registry->id,
    'platform_id' => $registry->platform_id,
];
PHP);

    try {
        $owner = ConcurrentTransactions::execute(<<<PHP
\$run = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['archive_run_id']});

return [
    'acquired' => app(\App\Services\Procurement\CrawlerRunManager::class)->acquireExecutionLease(run: \$run),
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()'),
];
PHP);
        ConcurrentTransactions::execute(<<<PHP
\App\Models\Procurement\Crawler\Run::query()->whereKey({$fixture['archive_run_id']})->update([
    'execution_lease_expires_at' => \Illuminate\Support\Facades\DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 second'"),
]);

return ['expired' => true];
PHP);
        $takeover = ConcurrentTransactions::execute(<<<PHP
config([
    'crawler.eis.block_size' => 2,
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
        if (\$cursor->day !== '2026-09-01' || \$cursor->blockFrom !== 501 || \$cursor->priceStack !== ['0:100']) {
            throw new \RuntimeException('Scanner did not resume the durable backlog checkpoint.');
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

return [
    'processed' => app(\App\Services\Procurement\Modes\BacklogRescanProcessor::class)->sweep(),
    'calls' => \$source->calls,
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()'),
];
PHP);
        $state = ConcurrentTransactions::execute(<<<PHP
\$archive = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['archive_run_id']});
\$fresh = \App\Models\Procurement\Crawler\Run::query()->findOrFail({$fixture['fresh_run_id']});

return [
    'status' => \$archive->status->value,
    'cursor' => \$archive->cursor->toArray(),
    'fresh_stats' => \$fresh->stats->toArray(),
];
PHP);

        expect($owner['acquired'])->toBeTrue()
            ->and($owner['backend_pid'])->not->toBe($takeover['backend_pid'])
            ->and($takeover['processed'])->toBe(1)
            ->and($takeover['calls'])->toBe(1)
            ->and($state['status'])->toBe(RunStatus::Completed->value)
            ->and($state['cursor']['day'])->toBe('2026-09-02')
            ->and($state['fresh_stats']['backlog_rescanned_at'])->not->toBeNull();
    } finally {
        ConcurrentTransactions::execute(<<<PHP
\Illuminate\Support\Facades\DB::table('prc_fetches')->whereIn('crawler_run_id', [{$fixture['fresh_run_id']}, {$fixture['archive_run_id']}])->delete();
\App\Models\Procurement\Crawler\Run::query()->whereIn('id', [{$fixture['fresh_run_id']}, {$fixture['archive_run_id']}])->delete();
\App\Models\Procurement\Crawler\Crawler::query()->whereIn('id', [{$fixture['fresh_crawler_id']}, {$fixture['archive_crawler_id']}])->delete();
\App\Models\Procurement\Platform\Registry::query()->whereKey({$fixture['registry_id']})->delete();
\App\Models\Procurement\Platform\Platform::query()->whereKey({$fixture['platform_id']})->delete();

return ['cleaned' => true];
PHP);
    }
});

test('нет archive-профиля реестра — warning, флаг не помечен, scan не вызван', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);

    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z']],
    ]);

    useBacklogRescanQueueSourceMethod([]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0)
        ->and(Run::query()->count())->toBe(1);

    $freshRun->refresh();
    expect($freshRun->stats['backlog_rescanned_at'] ?? null)->toBeNull();
});

test('possible_skip_window.from не определён — пропуск без пометки', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => null, 'to' => '2024-01-01T12:00:00Z']],
    ]);

    useBacklogRescanQueueSourceMethod([]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0);

    $freshRun->refresh();
    expect($freshRun->stats['backlog_rescanned_at'] ?? null)->toBeNull();
});

test('окно рескана сдвигается назад на window_overlap_days, лог несёт фактические границы', function () {
    config(['crawler.tracking.window_overlap_days' => 2]);
    Log::spy();

    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'cursor' => backlogFreshCursor(crawler: $freshCrawler),
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-05T00:00:00Z', 'to' => '2024-01-05T12:00:00Z']],
    ]);

    useBacklogRescanQueueSourceMethod([
        backlogRescanDrainedBatch(),
        backlogRescanDrainedBatch(),
        backlogRescanDrainedBatch(),
    ]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(1);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'перекрытое окно рескана')
            && $context['requested_from'] === '2024-01-05'
            && $context['overlap_days'] === 2
            && $context['from'] === '2024-01-03'
            && $context['to'] === '2024-01-05')
        ->once();
});

test('стоп-сигнал Pause между днями останавливает рескан целиком, fresh-прогон не помечается rescanned', function () {
    config(['crawler.tracking.window_overlap_days' => 2]);

    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    $archiveCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'cursor' => backlogFreshCursor(crawler: $freshCrawler),
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-05T00:00:00Z', 'to' => '2024-01-05T12:00:00Z']],
    ]);

    // overlap_days=2, from=2024-01-05 → перекрытое окно [2024-01-03; 2024-01-05] — 3 дня.
    $sourceMethod = useBacklogRescanStopSignalingSourceMethod(
        batches: [
            backlogRescanDrainedBatch(),
            backlogRescanDrainedBatch(),
        ],
        sideEffectsByCallIndex: [
            0 => fn () => Run::query()->where('crawler_id', $archiveCrawler->id)->update([
                'stop_requested_at' => Carbon::now(),
                'stop_kind' => RunStopKind::Pause->value,
            ]),
        ],
    );

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0)
        ->and($sourceMethod->calls)->toBe(1);

    $archiveRun = Run::query()->where('crawler_id', $archiveCrawler->id)->sole();
    expect($archiveRun->status)->toBe(RunStatus::Paused)
        ->and($archiveRun->status)->not->toBe(RunStatus::Completed);

    $freshRun->refresh();
    expect($freshRun->stats['backlog_rescanned_at'] ?? null)->toBeNull();
});

test('стоп-сигнал Cancel между днями останавливает рескан целиком, fresh-прогон не помечается rescanned', function () {
    config(['crawler.tracking.window_overlap_days' => 2]);

    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    $archiveCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'cursor' => backlogFreshCursor(crawler: $freshCrawler),
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-05T00:00:00Z', 'to' => '2024-01-05T12:00:00Z']],
    ]);

    $sourceMethod = useBacklogRescanStopSignalingSourceMethod(
        batches: [
            backlogRescanDrainedBatch(),
            backlogRescanDrainedBatch(),
        ],
        sideEffectsByCallIndex: [
            0 => fn () => Run::query()->where('crawler_id', $archiveCrawler->id)->update([
                'stop_requested_at' => Carbon::now(),
                'stop_kind' => RunStopKind::Cancel->value,
            ]),
        ],
    );

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0)
        ->and($sourceMethod->calls)->toBe(1);

    $archiveRun = Run::query()->where('crawler_id', $archiveCrawler->id)->sole();
    expect($archiveRun->status)->toBe(RunStatus::Cancelled)
        ->and($archiveRun->status)->not->toBe(RunStatus::Completed);

    $freshRun->refresh();
    expect($freshRun->stats['backlog_rescanned_at'] ?? null)->toBeNull();
});

test('источник всё время бросает — блоки пропускаются, страховка останавливает этап, archive-run не провален, флаг непомечен', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'cursor' => backlogFreshCursor(crawler: $freshCrawler),
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z']],
    ]);

    // Пустая очередь батчей — QueueSourceMethod бросит OutOfBoundsException при первом вызове.
    useBacklogRescanQueueSourceMethod([]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0);

    $archiveRun = Run::query()->where('crawler_id', '!=', $freshCrawler->id)->sole();
    expect($archiveRun->status)->toBe(RunStatus::Paused)
        ->and(SourceCircuit::query()->sole()->status())->toBe(SourceStageStatus::Held);

    $freshRun->refresh();
    expect($freshRun->stats['backlog_rescanned_at'] ?? null)->toBeNull();
});
