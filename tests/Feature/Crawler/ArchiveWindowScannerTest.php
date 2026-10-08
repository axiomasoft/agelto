<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\ArchiveWindowScanResult;
use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\ArchiveWindowScanner;
use App\Services\Procurement\Contracts\SourceMethod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;

final class ArchiveContinuationSourceMethod implements SourceMethod
{
    private int $cursorIndex = 0;

    /**
     * @param  list<ListingBatch|PlatformBudgetExhaustedException>  $responses
     */
    public function __construct(private readonly array $responses) {}

    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        $response = $this->responses[$this->cursorIndex++];

        if ($response instanceof PlatformBudgetExhaustedException) {
            throw $response;
        }

        return $response;
    }

    public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails
    {
        return new ProcurementDetails;
    }

    public function isAvailable(): bool
    {
        return true;
    }
}

/**
 * @return array<string, string>
 */
function archiveScannerCsvRow(string $registryNumber): array
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

/**
 * @param  list<string>  $registryNumbers
 */
function archiveScannerBatch(array $registryNumbers, ?string $dedupSignature): ListingBatch
{
    $rows = array_map(archiveScannerCsvRow(...), $registryNumbers);

    return new ListingBatch(
        rows: $rows,
        rawBody: 'raw csv body',
        dedupSignature: $dedupSignature,
        rowsTotal: count($rows),
        httpStatus: 200,
        durationMs: 10,
        requestParams: [],
    );
}

function useArchiveQueueSourceMethod(array $batches): void
{
    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(repeatStableWindows: true, batches: $batches));

    config(['crawler.platforms.eis.source_methods' => [
        SourceMethodEnum::Csv->value => QueueSourceMethod::class,
    ]]);
}

/**
 * @param  list<ListingBatch|PlatformBudgetExhaustedException>  $responses
 */
function useArchiveContinuationSourceMethod(array $responses): void
{
    app()->instance(ArchiveContinuationSourceMethod::class, new ArchiveContinuationSourceMethod(responses: $responses));
    config(['crawler.platforms.eis.source_methods' => [
        SourceMethodEnum::Csv->value => ArchiveContinuationSourceMethod::class,
    ]]);
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
    config([
        'crawler.platforms.eis.block_size' => 2,
        'crawler.archive.price_ceiling' => 100,
        'crawler.archive.min_price_interval' => 10,
        'crawler.archive.max_blocks_per_window' => 50,
    ]);
});

function archiveWindowFilter(): WindowFilter
{
    return new WindowFilter(dateField: 'publish', dateFrom: Carbon::parse('2024-01-01'), dateTo: Carbon::parse('2024-01-01'));
}

test('окно дренируется на частичном блоке, стек опустошён', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);

    useArchiveQueueSourceMethod([
        archiveScannerBatch(['0173200001234000001', '0173200001234000002'], dedupSignature: 'sig-1'),
        archiveScannerBatch(['0173200001234000003', '0173200001234000004'], dedupSignature: 'sig-2'),
        archiveScannerBatch(['0173200001234000005'], dedupSignature: 'sig-3'),
    ]);

    $result = app(ArchiveWindowScanner::class)->scan(crawler: $crawler, run: $run, filter: archiveWindowFilter());

    expect($result)->toBe(ArchiveWindowScanResult::Drained)
        ->and(Procurement::query()->count())->toBe(5)
        ->and($run->fresh()->cursor['priceStack'])->toBeNull()
        ->and($run->fresh()->cursor['blockFrom'])->toBe(1);
});

test('cap по повтору dedup_signature делит интервал пополам, обе половины дренируются', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);

    $repeatedRows = ['0173200001234000010', '0173200001234000011'];
    useArchiveQueueSourceMethod([
        archiveScannerBatch($repeatedRows, dedupSignature: 'dup-signature'),
        archiveScannerBatch($repeatedRows, dedupSignature: 'dup-signature'),
        archiveScannerBatch(['0173200001234000014'], dedupSignature: 'sig-lower'),
        archiveScannerBatch(['0173200001234000015'], dedupSignature: 'sig-upper'),
    ]);

    app(ArchiveWindowScanner::class)->scan(crawler: $crawler, run: $run, filter: archiveWindowFilter());

    expect(Procurement::query()->count())->toBe(4)
        ->and($run->fresh()->cursor['priceStack'])->toBeNull();
});

test('archive max-block detector survives checkpoints and consumes one listing block per continuation', function () {
    config(['crawler.archive.max_blocks_per_window' => 2]);
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);

    useArchiveContinuationSourceMethod([
        archiveScannerBatch(['0173200001234000040', '0173200001234000041'], dedupSignature: 'sig-1'),
        PlatformBudgetExhaustedException::forHost(host: 'eis.test', maxWaitSeconds: 0),
        archiveScannerBatch(['0173200001234000042', '0173200001234000043'], dedupSignature: 'sig-2'),
    ]);

    $first = app(ArchiveWindowScanner::class)->scan(crawler: $crawler, run: $run, filter: archiveWindowFilter());

    expect($first)->toBe(ArchiveWindowScanResult::Checkpointed)
        ->and($run->fresh()->cursor['lastDedupSignature'])->toBe('sig-1')
        ->and($run->fresh()->cursor['blocksInInterval'])->toBe(1);

    $second = app(ArchiveWindowScanner::class)->scan(crawler: $crawler, run: $run->fresh(), filter: archiveWindowFilter());

    expect($second)->toBe(ArchiveWindowScanResult::OperatorDebt)
        ->and($run->fresh()->cursor['priceStack'])->toBe(['0:100'])
        ->and($run->fresh()->cursor['blocksInInterval'])->toBe(2)
        ->and(Procurement::query()->count())->toBe(4);
});

test('cap на минимальном ценовом интервале остаётся operator debt без потери стека', function () {
    config(['crawler.archive.price_ceiling' => 10, 'crawler.archive.min_price_interval' => 10]);
    Log::spy();

    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);

    useArchiveQueueSourceMethod([
        archiveScannerBatch(['0173200001234000020', '0173200001234000021'], dedupSignature: 'dup-signature'),
        archiveScannerBatch(['0173200001234000022', '0173200001234000023'], dedupSignature: 'dup-signature'),
    ]);

    $result = app(ArchiveWindowScanner::class)->scan(crawler: $crawler, run: $run, filter: archiveWindowFilter());

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'price interval at minimum'))
        ->once();

    expect($result)->toBe(ArchiveWindowScanResult::OperatorDebt)
        ->and(Procurement::query()->count())->toBe(4)
        ->and($run->fresh()->cursor['priceStack'])->toBe(['0:10']);
});

test('max_blocks_per_window сохраняет хвост до resume с изменённым лимитом', function () {
    config(['crawler.archive.max_blocks_per_window' => 2]);
    Log::spy();

    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);

    useArchiveQueueSourceMethod([
        archiveScannerBatch(['0173200001234000030', '0173200001234000031'], dedupSignature: 'sig-1'),
        archiveScannerBatch(['0173200001234000032', '0173200001234000033'], dedupSignature: 'sig-2'),
        archiveScannerBatch(['0173200001234000034'], dedupSignature: 'sig-3'),
    ]);

    $first = app(ArchiveWindowScanner::class)->scan(crawler: $crawler, run: $run, filter: archiveWindowFilter());

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'max blocks per price interval'))
        ->once();

    expect($first)->toBe(ArchiveWindowScanResult::OperatorDebt)
        ->and(Procurement::query()->count())->toBe(4)
        ->and($run->fresh()->cursor['priceStack'])->toBe(['0:100']);

    config(['crawler.archive.max_blocks_per_window' => 3]);

    $second = app(ArchiveWindowScanner::class)->scan(crawler: $crawler, run: $run->fresh(), filter: archiveWindowFilter());

    expect($second)->toBe(ArchiveWindowScanResult::Drained)
        ->and(Procurement::query()->count())->toBe(5)
        ->and($run->fresh()->cursor['priceStack'])->toBeNull();
});

test('стартовый ценовой стек берётся из priceTo фильтра профиля, config остаётся дефолтом без него', function () {
    // max_blocks_per_window=0 форсирует деление на ПЕРВОЙ итерации, ДО единого collectListing():
    // Log-контекст несёт стартовый интервал стека до какого-либо дробления — точная проба П-9.
    config(['crawler.archive.price_ceiling' => 1_000_000_000, 'crawler.archive.max_blocks_per_window' => 0]);
    Log::spy();

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);

    $crawlerWithPrice = Crawler::factory()
        ->filter(new EisSearchFilter(priceTo: 200_000_000, okpd2Codes: ['43']))
        ->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive]);
    $runWithPrice = Run::factory()->for($crawlerWithPrice)->create(['cursor' => null]);

    app(ArchiveWindowScanner::class)->scan(crawler: $crawlerWithPrice, run: $runWithPrice, filter: archiveWindowFilter());

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'max blocks per price interval') && $context['interval'] === '0:200000000')
        ->once();

    $crawlerWithoutPrice = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive]);
    $runWithoutPrice = Run::factory()->for($crawlerWithoutPrice)->create(['cursor' => null]);

    app(ArchiveWindowScanner::class)->scan(crawler: $crawlerWithoutPrice, run: $runWithoutPrice, filter: archiveWindowFilter());

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'max blocks per price interval') && $context['interval'] === '0:1000000000')
        ->once();
});

test('отказ блока сохраняет интервал и курсор, инцидент записан вне транзакции', function (): void {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);

    useArchiveQueueSourceMethod([
        new ListingBatch(rows: [], rawBody: '<html>403</html>', dedupSignature: null, rowsTotal: 0, httpStatus: 403, durationMs: 5, requestParams: ['from' => '1']),
        archiveScannerBatch(['0173200001234000003', '0173200001234000004'], dedupSignature: 'sig-2'),
        archiveScannerBatch(['0173200001234000005'], dedupSignature: 'sig-3'),
    ]);

    // Инцидент пишется ВНЕ транзакции блока (аренды): уровень вложенности при записи равен
    // базовому уровню теста, значит откат блока его не сотрёт.
    $baseLevel = DB::transactionLevel();
    $levels = [];
    SourceIncident::created(function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    $result = app(ArchiveWindowScanner::class)->scan(crawler: $crawler, run: $run, filter: archiveWindowFilter());

    expect($result)->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->refresh()->cursor['blockFrom'] ?? 1)->toBe(1)
        ->and(Procurement::query()->count())->toBe(0)
        ->and(SourceIncident::query()->where('type', CrawlerEventCode::CsvSourceBlocked)->sole()->type)->toBe(CrawlerEventCode::CsvSourceBlocked)
        ->and($levels)->toBe([$baseLevel, $baseLevel]);
});

test('системный отказ в archive: курсор остаётся на блоке, прогон на паузе источника', function (): void {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);
    SourceCircuit::factory()->create(['platform_id' => $crawler->registry->platform_id, 'failure_threshold' => 1]);

    useArchiveQueueSourceMethod([
        new ListingBatch(rows: [], rawBody: '', dedupSignature: null, rowsTotal: 0, httpStatus: 503, durationMs: 5, requestParams: ['from' => '1']),
    ]);

    $result = app(ArchiveWindowScanner::class)->scan(crawler: $crawler, run: $run, filter: archiveWindowFilter());
    $run->refresh();

    expect($result)->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->status)->toBe(RunStatus::Paused)
        ->and($run->stats['source_suspended'] ?? null)->toBeTrue()
        ->and($run->cursor['blockFrom'] ?? 1)->toBe(1);
});

test('ошибка записи блока откатывает транзакцию и сохраняет курсор, прежние данные целы', function (): void {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);

    useArchiveQueueSourceMethod([
        archiveScannerBatch(['0173200001234000001', '0173200001234000002'], dedupSignature: 'sig-1'),
        archiveScannerBatch(['0173200001234000003', '0173200001234000004'], dedupSignature: 'sig-2'),
        archiveScannerBatch(['0173200001234000005'], dedupSignature: 'sig-3'),
    ]);
    // Отказ уровня блока ПОСЛЕ записи его строк (журнал fetch'а второго блока): строки блока
    // должны откатиться вместе с транзакцией, а не остаться наполовину.
    $fetches = 0;
    Fetch::creating(function () use (&$fetches): void {
        if (++$fetches === 2) {
            throw new LogicException('bug');
        }
    });

    $result = app(ArchiveWindowScanner::class)->scan(crawler: $crawler, run: $run, filter: archiveWindowFilter());

    expect($result)->toBe(ArchiveWindowScanResult::Stopped)
        ->and(Procurement::query()->orderBy('registry_number')->pluck('registry_number')->all())
        ->toBe(['0173200001234000001', '0173200001234000002'])
        ->and(SourceIncident::query()->where('type', CrawlerEventCode::CsvCodeError)->sole()->type)->toBe(CrawlerEventCode::CsvCodeError)
        ->and($run->refresh()->status)->toBe(RunStatus::Paused)
        ->and($run->cursor['blockFrom'])->toBe(3);
});
