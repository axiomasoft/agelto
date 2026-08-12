<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\Modes\BacklogRescanProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;

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
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z']],
    ]);

    useBacklogRescanQueueSourceMethod([backlogRescanDrainedBatch()]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(1);

    $archiveRun = Run::query()->where('crawler_id', '!=', $freshCrawler->id)->sole();
    expect($archiveRun->status)->toBe(RunStatus::Completed);

    $freshRun->refresh();
    expect($freshRun->stats['backlog_rescanned_at'])->not->toBeNull();
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

test('исключение внутри scan — archive-run failed, флаг остаётся непомеченным', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z']],
    ]);

    // Пустая очередь батчей — QueueSourceMethod бросит OutOfBoundsException при первом вызове.
    useBacklogRescanQueueSourceMethod([]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0);

    $archiveRun = Run::query()->where('crawler_id', '!=', $freshCrawler->id)->sole();
    expect($archiveRun->status)->toBe(RunStatus::Failed);

    $freshRun->refresh();
    expect($freshRun->stats['backlog_rescanned_at'] ?? null)->toBeNull();
});
