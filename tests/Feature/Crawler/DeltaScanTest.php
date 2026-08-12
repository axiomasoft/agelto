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
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\Modes\DeltaScanProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;

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
    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: $batches));

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
        'crawler.eis.block_size' => 2,
        'crawler.archive.price_ceiling' => 1_000_000_000,
        'crawler.tracking.window_overlap_days' => 2,
    ]);
});

test('нет активного archive-профиля реестра — warning, run не создаётся', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);

    Log::spy();

    $run = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);

    expect($run)->toBeNull()
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

    $run = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);

    expect($run)->toBeNull()
        ->and($ownerRun->refresh()->status)->toBe(RunStatus::Running)
        ->and($ownerRun->purpose)->toBe(RunPurpose::Profile);
});

test('активный archive-профиль реестра — дренирует окно [сегодня − overlap; сегодня] по update, run завершён', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    $archiveCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    // window_overlap_days=2, "сегодня"=2026-08-05 → окно [2026-08-03; 2026-08-05] — 3 дня.
    useDeltaScanQueueSourceMethod([
        deltaScanBatch('p46-delta-day-1'),
        deltaScanBatch('p46-delta-day-2'),
        deltaScanBatch('p46-delta-day-3'),
    ]);

    $run = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);

    expect($run)->not->toBeNull()
        ->and($run->crawler_id)->toBe($archiveCrawler->id)
        ->and($run->status)->toBe(RunStatus::Completed)
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

    $run = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);

    expect($sourceMethod->calls)->toBe(1)
        ->and($run->fresh()->status)->toBe(RunStatus::Paused)
        ->and($run->fresh()->status)->not->toBe(RunStatus::Completed)
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

    $run = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);

    expect($sourceMethod->calls)->toBe(1)
        ->and($run->fresh()->status)->toBe(RunStatus::Cancelled)
        ->and($run->fresh()->status)->not->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(1);
});
