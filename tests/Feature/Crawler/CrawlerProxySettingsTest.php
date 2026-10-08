<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\ArchiveWindowScanner;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\Modes\FreshRunProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

/**
 * Одна валидная строка CSV-блока (набор колонок, которые понимает `EisCsvRowMapper`).
 *
 * @return array<string, string>
 */
function proxySettingsCsvRow(string $registryNumber): array
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
 * Двойник `SourceMethod`, фиксирующий `proxy_mode`/`proxy_country` профиля НА МОМЕНТ
 * КАЖДОГО вызова `collectListing()` — так проверяется, что правка настроек посреди
 * прогона доходит до следующей итерации, а не остаётся снимком старта.
 */
final class ProxySettingsRecordingSourceMethod implements SourceMethod
{
    /** @var list<array{proxy_mode: string, proxy_country: ?string}> */
    public array $observed = [];

    private int $cursorIndex = 0;

    /**
     * @param  list<ListingBatch>  $batches
     */
    public function __construct(
        private readonly array $batches,
        private readonly ?Closure $beforeReturn = null,
    ) {}

    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        $this->observed[] = ['proxy_mode' => $crawler->proxy_mode->value, 'proxy_country' => $crawler->proxy_country];

        $batch = $this->batches[$this->cursorIndex];

        if ($this->beforeReturn !== null) {
            ($this->beforeReturn)($this->cursorIndex);
        }

        $this->cursorIndex++;

        return $batch;
    }

    public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails
    {
        return new ProcurementDetails(registryNumber: $registryNumber, procedureUrlType: 'fz44');
    }

    public function isAvailable(): bool
    {
        return true;
    }
}

/**
 * @param  list<ListingBatch>  $batches
 */
function useProxySettingsSourceMethod(array $batches, ?Closure $beforeReturn = null): ProxySettingsRecordingSourceMethod
{
    $double = new ProxySettingsRecordingSourceMethod(batches: $batches, beforeReturn: $beforeReturn);
    app()->instance(ProxySettingsRecordingSourceMethod::class, $double);

    config(['crawler.platforms.eis.source_methods' => [
        SourceMethodEnum::Csv->value => ProxySettingsRecordingSourceMethod::class,
    ]]);

    return $double;
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
});

test('FreshRunProcessor останавливается при изменении proxy маршрута между блоками', function () {
    $crawler = Crawler::factory()->withProxies(Proxy::factory()->create())->create(['proxy_country' => null]);

    $sourceMethod = useProxySettingsSourceMethod(
        batches: [
            new ListingBatch(rows: [proxySettingsCsvRow('0173200001234000001')], rawBody: 'raw', dedupSignature: 'sig-1', rowsTotal: 1, httpStatus: 200, durationMs: 1, requestParams: []),
            new ListingBatch(rows: [], rawBody: 'raw', dedupSignature: null, rowsTotal: 0, httpStatus: 200, durationMs: 1, requestParams: []),
        ],
        beforeReturn: function (int $index) use ($crawler): void {
            if ($index === 0) {
                Crawler::query()->whereKey($crawler->id)->update(['proxy_mode' => CrawlerProxyMode::Direct->value, 'proxy_country' => 'DE']);
            }
        },
    );

    $run = app(FreshRunProcessor::class)->run(crawler: $crawler);
    expect($sourceMethod->observed)->toBe([
        ['proxy_mode' => 'profile', 'proxy_country' => null],
    ])->and($run->status)->toBe(RunStatus::Paused)
        ->and($run->stats->get('stop_reason'))->toContain('между блоками');
});

test('ArchiveWindowScanner перечитывает proxy_mode/proxy_country профиля перед каждой итерацией', function () {
    config([
        'crawler.platforms.eis.block_size' => 1,
        'crawler.archive.price_ceiling' => 100,
        'crawler.archive.min_price_interval' => 10,
        'crawler.archive.max_blocks_per_window' => 50,
    ]);

    $crawler = Crawler::factory()
        ->withProxies(Proxy::factory()->create())
        ->create(['mode' => CrawlerMode::Archive, 'proxy_country' => null]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);

    $sourceMethod = useProxySettingsSourceMethod(
        batches: [
            new ListingBatch(rows: [proxySettingsCsvRow('0173200001234000010')], rawBody: 'raw', dedupSignature: 'sig-1', rowsTotal: 1, httpStatus: 200, durationMs: 1, requestParams: []),
            new ListingBatch(rows: [], rawBody: 'raw', dedupSignature: 'sig-2', rowsTotal: 0, httpStatus: 200, durationMs: 1, requestParams: []),
        ],
        beforeReturn: function (int $index) use ($crawler): void {
            if ($index === 0) {
                Crawler::query()->whereKey($crawler->id)->update(['proxy_mode' => CrawlerProxyMode::Direct->value, 'proxy_country' => 'DE']);
            }
        },
    );

    $filter = new WindowFilter(dateField: 'publish', dateFrom: Carbon::parse('2024-01-01'), dateTo: Carbon::parse('2024-01-01'));

    app(ArchiveWindowScanner::class)->scan(crawler: $crawler, run: $run, filter: $filter);

    expect($sourceMethod->observed)->toBe([
        ['proxy_mode' => 'profile', 'proxy_country' => null],
        ['proxy_mode' => 'direct', 'proxy_country' => 'DE'],
        ['proxy_mode' => 'direct', 'proxy_country' => 'DE'],
    ]);
});

test('фабрика по умолчанию сохраняет default-профиль, а withProxies задаёт profile mode', function (): void {
    $directCrawler = Crawler::factory()->create();
    $proxy = Proxy::factory()->create();
    $proxyCrawler = Crawler::factory()->withProxies($proxy)->create();

    expect($directCrawler->proxy_mode)->toBe(CrawlerProxyMode::Default)
        ->and($proxyCrawler->proxy_mode)->toBe(CrawlerProxyMode::Profile)
        ->and($proxyCrawler->proxies()->pluck('prc_proxies.id')->all())->toBe([$proxy->id]);
});

test('профиль с включёнными прокси без явной привязки не сохраняется', function (): void {
    Crawler::factory()->create(['proxy_mode' => CrawlerProxyMode::Profile]);
})->throws(ValidationException::class, 'привяжите');
