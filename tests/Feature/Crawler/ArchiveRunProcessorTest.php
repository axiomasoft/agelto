<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\Modes\ArchiveRunProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;

/**
 * @return array<string, string>
 */
function archiveRunCsvRow(string $registryNumber): array
{
    return [
        'Реестровый номер закупки' => $registryNumber,
        'Способ определения поставщика' => 'Электронный аукцион',
        'Наименование закупки' => 'Поставка оргтехники',
        'Этап закупки' => 'Подача заявок',
        'Идентификационный код закупки (ИКЗ)' => '243773601234512345100100001',
        'НМЦК' => '1 250 000,50',
        'Валюта' => 'RUB',
        'ОКПД2' => '26.20.11.110',
        'Дата размещения' => '01.03.2026',
        'Дата обновления' => '02.03.2026 10:15:00',
        'Начало подачи заявок' => '01.03.2026',
        'Окончание подачи заявок' => '15.03.2026',
        'Наименование заказчика' => 'ГБУ «Тестовое учреждение»',
    ];
}

function archiveRunBatch(string $registryNumber, string $dedupSignature): ListingBatch
{
    return new ListingBatch(
        rows: [archiveRunCsvRow($registryNumber)],
        rawBody: 'raw csv body',
        dedupSignature: $dedupSignature,
        rowsTotal: 1,
        httpStatus: 200,
        durationMs: 10,
        requestParams: [],
    );
}

function useArchiveRunQueueSourceMethod(array $batches): void
{
    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: $batches));

    config(['crawler.source_methods' => [
        SourceMethodEnum::Csv->value => QueueSourceMethod::class,
    ]]);
}

/**
 * `QueueSourceMethod` не умеет менять состояние строки прогона В ПРОЦЕССЕ обхода — этот
 * двойник (P3.7) вдобавок к выдаче батча по индексу вызова синхронно применяет побочный эффект
 * (постановку стоп-сигнала), симулируя оператора, нажавшего «Остановить»/«Отменить» МЕЖДУ днями.
 * Возвращает сам двойник — тест читает `->calls`, чтобы доказать, что источник НЕ был опрошен
 * на дне, следующем за тем, где стоп-гейт его обнаружил.
 *
 * @param  list<ListingBatch>  $batches
 * @param  array<int, Closure>  $sideEffectsByCallIndex  0-based индекс вызова → эффект ПОСЛЕ выдачи батча
 */
function useArchiveRunStopSignalingSourceMethod(array $batches, array $sideEffectsByCallIndex = []): object
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
    config(['crawler.source_methods' => [
        SourceMethodEnum::Csv->value => $double::class,
    ]]);

    return $double;
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
    config([
        // Блок = 2 строки, наши батчи по 1 строке — сразу "частичный блок" (drain).
        'crawler.eis.block_size' => 2,
        'crawler.archive.price_ceiling' => 100,
        'crawler.archive.min_price_interval' => 10,
        'crawler.archive.max_blocks_per_window' => 50,
    ]);
});

test('период из 2 дней — scan вызван на каждый день, курсор прошёл оба дня, run completed', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);

    useArchiveRunQueueSourceMethod([
        archiveRunBatch('0173200001234000001', 'sig-day-1'),
        archiveRunBatch('0173200001234000002', 'sig-day-2'),
    ]);

    $run = app(ArchiveRunProcessor::class)->run(
        crawler: $crawler,
        from: Carbon::parse('2024-01-01'),
        to: Carbon::parse('2024-01-02'),
    );

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(2)
        ->and($run->cursor['day'])->toBe('2024-01-03');
});

test('оборванный прогон (paused с курсором на 2-м дне) резюмится со 2-го дня, не с 1-го', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);

    $existingRun = Run::factory()->for($crawler)->create([
        'status' => RunStatus::Paused,
        'cursor' => (new ListingCursor(blockFrom: 1, day: '2024-01-02'))->toArray(),
    ]);

    useArchiveRunQueueSourceMethod([
        archiveRunBatch('0173200001234000003', 'sig-day-2-only'),
    ]);

    $run = app(ArchiveRunProcessor::class)->run(
        crawler: $crawler,
        from: Carbon::parse('2024-01-01'),
        to: Carbon::parse('2024-01-02'),
    );

    expect($run->id)->toBe($existingRun->id)
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(1);
});

test('стоп-сигнал Pause между днями останавливает archive-прогон целиком, следующий день не обходится', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);

    $sourceMethod = useArchiveRunStopSignalingSourceMethod(
        batches: [
            archiveRunBatch('0173200001234000201', 'sig-day-1'),
            archiveRunBatch('0173200001234000203', 'sig-day-3-must-not-be-fetched'),
        ],
        sideEffectsByCallIndex: [
            0 => fn () => Run::query()->where('crawler_id', $crawler->id)->update([
                'stop_requested_at' => Carbon::now(),
                'stop_kind' => RunStopKind::Pause->value,
            ]),
        ],
    );

    $run = app(ArchiveRunProcessor::class)->run(
        crawler: $crawler,
        from: Carbon::parse('2024-01-01'),
        to: Carbon::parse('2024-01-03'),
    );

    expect($sourceMethod->calls)->toBe(1)
        ->and($run->fresh()->status)->toBe(RunStatus::Paused)
        ->and($run->fresh()->status)->not->toBe(RunStatus::Completed)
        ->and($run->fresh()->cursor['day'])->toBe('2024-01-02')
        ->and(Procurement::query()->count())->toBe(1);
});

test('стоп-сигнал Cancel между днями останавливает archive-прогон целиком, статус терминален', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);

    $sourceMethod = useArchiveRunStopSignalingSourceMethod(
        batches: [
            archiveRunBatch('0173200001234000301', 'sig-day-1-cancel'),
            archiveRunBatch('0173200001234000303', 'sig-day-3-must-not-be-fetched'),
        ],
        sideEffectsByCallIndex: [
            0 => fn () => Run::query()->where('crawler_id', $crawler->id)->update([
                'stop_requested_at' => Carbon::now(),
                'stop_kind' => RunStopKind::Cancel->value,
            ]),
        ],
    );

    $run = app(ArchiveRunProcessor::class)->run(
        crawler: $crawler,
        from: Carbon::parse('2024-01-01'),
        to: Carbon::parse('2024-01-03'),
    );

    expect($sourceMethod->calls)->toBe(1)
        ->and($run->fresh()->status)->toBe(RunStatus::Cancelled)
        ->and($run->fresh()->status)->not->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(1);
});
