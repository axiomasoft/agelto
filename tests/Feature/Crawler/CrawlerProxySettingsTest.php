<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Services\Procurement\ArchiveWindowScanner;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\Modes\FreshRunProcessor;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxy\ProxyBindingResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

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
        $this->observed[] = ['route_profile_id' => $crawler->route_profile_id];

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
    LegacyBusinessNetwork::install();
    Sleep::fake();
    Storage::fake('s3');
});

test('FreshRunProcessor останавливается при изменении proxy маршрута между блоками', function () {
    $crawler = Crawler::factory()->withProxies(Proxy::factory()->create())->create();

    $initialProfile = $crawler->route_profile_id;
    $sourceMethod = useProxySettingsSourceMethod(
        batches: [
            new ListingBatch(rows: [proxySettingsCsvRow('0173200001234000001')], rawBody: 'raw', dedupSignature: 'sig-1', rowsTotal: 1, httpStatus: 200, durationMs: 1, requestParams: []),
            new ListingBatch(rows: [], rawBody: 'raw', dedupSignature: null, rowsTotal: 0, httpStatus: 200, durationMs: 1, requestParams: []),
        ],
        beforeReturn: function (int $index) use ($crawler): void {
            if ($index === 0) {
                Crawler::query()->whereKey($crawler->id)->update(['route_profile_id' => canonicalSettingsDirectProfile()]);
            }
        },
    );

    $run = app(FreshRunProcessor::class)->run(crawler: $crawler);
    expect($sourceMethod->observed)->toBe([
        ['route_profile_id' => $initialProfile],
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
        ->create(['mode' => CrawlerMode::Archive]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);

    $initialProfile = $crawler->route_profile_id;
    $sourceMethod = useProxySettingsSourceMethod(
        batches: [
            new ListingBatch(rows: [proxySettingsCsvRow('0173200001234000010')], rawBody: 'raw', dedupSignature: 'sig-1', rowsTotal: 1, httpStatus: 200, durationMs: 1, requestParams: []),
            new ListingBatch(rows: [], rawBody: 'raw', dedupSignature: 'sig-2', rowsTotal: 0, httpStatus: 200, durationMs: 1, requestParams: []),
        ],
        beforeReturn: function (int $index) use ($crawler): void {
            if ($index === 0) {
                Crawler::query()->whereKey($crawler->id)->update(['route_profile_id' => canonicalSettingsDirectProfile()]);
            }
        },
    );

    $filter = new WindowFilter(dateField: 'publish', dateFrom: Carbon::parse('2024-01-01'), dateTo: Carbon::parse('2024-01-01'));

    app(ArchiveWindowScanner::class)->scan(crawler: $crawler, run: $run, filter: $filter);

    expect($sourceMethod->observed)->toBe([
        ['route_profile_id' => $initialProfile],
        ['route_profile_id' => canonicalSettingsDirectProfile()],
        ['route_profile_id' => canonicalSettingsDirectProfile()],
    ]);
});

test('фабрика по умолчанию сохраняет default-профиль, а withProxies задаёт profile mode', function (): void {
    $directCrawler = Crawler::factory()->create();
    $proxy = Proxy::factory()->create();
    $proxyCrawler = Crawler::factory()->withProxies($proxy)->create();

    expect($directCrawler->route_profile_id)->toBeNull()
        ->and($proxyCrawler->route_profile_id)->not->toBeNull()
        ->and(array_values(app(ProxyBindingResolver::class)->forCrawler($proxyCrawler)->bindings)[0]->id->value)->toBe($proxy->route_id);
});

test('профиль с включёнными прокси без явной привязки не сохраняется', function (): void {
    app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->ensureProfile('settings.empty'));
    $profile = DB::table('prc_network_route_profiles')->where('profile_key', 'settings.empty')->sole();
    $crawler = Crawler::factory()->create(['route_profile_id' => $profile->id]);
    expect(app(ProxyBindingResolver::class)->forCrawler($crawler)->bindings)->toBe([])
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

function canonicalSettingsDirectProfile(): string
{
    $route = DB::table('prc_network_routes')->where('kind', 'direct')->sole();
    app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->ensureProfile('settings.direct', $route->id));

    return DB::table('prc_network_route_profiles')->where('profile_key', 'settings.direct')->sole()->id;
}
