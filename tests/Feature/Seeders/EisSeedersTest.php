<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Crawler\RunStopPolicy;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Services\Procurement\Eis\EisCsvUrlBuilder;
use Database\Seeders\Procurement\CrawlerProfileSeeder;
use Database\Seeders\Procurement\EisPlatformSeeder;
use Database\Seeders\Procurement\Okpd2NodeSeeder;
use Database\Seeders\StatusMappingSeeder;
use Illuminate\Support\Facades\Artisan;

test('сид заводит площадку ЕИС и реестр fz44 с настройками карточки', function (): void {
    $this->seed(EisPlatformSeeder::class);

    $platform = Platform::query()->where('code', 'eis')->sole();
    $registry = Registry::query()->where('platform_id', $platform->id)->where('code', 'fz44')->sole();

    expect($registry->settings['procedure_url_types'])->toHaveKey('Электронный аукцион', 'ea20')
        ->and($registry->settings['card_url_templates']['ea20']['common-info'])
        ->toBe('https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber={regNumber}');
});

test('повторный прогон сида площадки идемпотентен — без дублей и ротации UUID реестра', function (): void {
    $this->seed(EisPlatformSeeder::class);

    $registry = Registry::query()->where('code', 'fz44')->sole();
    $registryId = $registry->id;
    $registryUuid = $registry->uuid;

    $this->seed(EisPlatformSeeder::class);

    $reseededRegistry = Registry::query()->where('code', 'fz44')->sole();

    expect(Platform::query()->where('code', 'eis')->count())->toBe(1)
        ->and(Registry::query()->where('code', 'fz44')->count())->toBe(1)
        ->and($reseededRegistry->id)->toBe($registryId)
        ->and($reseededRegistry->uuid)->toBe($registryUuid);
});

test('после сида площадки StatusMappingSeeder заводит матрицу без предупреждения', function (): void {
    $this->seed(EisPlatformSeeder::class);

    Artisan::call('db:seed', ['--class' => StatusMappingSeeder::class]);

    $registry = Registry::query()->where('code', 'fz44')->sole();

    expect(StatusMapping::query()->where('registry_id', $registry->id)->count())
        ->toBe(4);
});

test('сид заводит два профиля обхода — архивный активен, fresh выключен', function (): void {
    $this->seed(EisPlatformSeeder::class);
    $this->seed(CrawlerProfileSeeder::class);

    $registry = Registry::query()->where('code', 'fz44')->sole();
    $crawlers = Crawler::query()->where('registry_id', $registry->id)->get()->keyBy('mode');

    expect($crawlers)->toHaveCount(2);

    $archive = $crawlers[CrawlerMode::Archive->value];
    expect($archive->is_active)->toBeTrue()
        ->and($archive->execution_lane)->toBe(CrawlerExecutionLane::Long)
        ->and($archive->use_proxy)->toBeFalse()
        ->and($archive->filter->stageCompleted)->toBeTrue()
        ->and($archive->options?->toArray()['columns']['registryNumberCsv'] ?? null)->toBeTrue();

    $fresh = $crawlers[CrawlerMode::Fresh->value];
    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->execution_lane)->toBe(CrawlerExecutionLane::Standard)
        ->and($fresh->use_proxy)->toBeFalse()
        ->and($fresh->filter->okpd2Codes)->toBe(['43'])
        ->and($fresh->schedule)->toBe('*/30 * * * *');
});

test('сид заводит архивный профиль с окном дат прогона (P3.8, D113)', function (): void {
    $this->seed(EisPlatformSeeder::class);
    $this->seed(CrawlerProfileSeeder::class);

    $registry = Registry::query()->where('code', 'fz44')->sole();
    $archive = Crawler::query()->where('registry_id', $registry->id)->where('mode', CrawlerMode::Archive)->sole();

    expect($archive->filter->archiveWindowFrom()?->toDateString())->toBe('2025-01-01')
        ->and($archive->filter->archiveWindowTo()?->toDateString())->toBe('2025-12-31');
});

/**
 * P3.5: поля сортировки и `stop_policy` — единственная проверка, что заведённые фазами P3.1/P3.3
 * поля совместимы друг с другом (архивный профиль сортирует по возрастанию даты размещения без
 * стоп-условия, fresh — по убыванию даты обновления со стоп-условием «только актуальные данные»).
 */
test('сид заводит профили с сортировкой выдачи и политикой остановки из брифа', function (): void {
    $this->seed(EisPlatformSeeder::class);
    $this->seed(CrawlerProfileSeeder::class);

    $registry = Registry::query()->where('code', 'fz44')->sole();
    $crawlers = Crawler::query()->where('registry_id', $registry->id)->get()->keyBy('mode');

    $archive = $crawlers[CrawlerMode::Archive->value];
    expect($archive->filter->sortField)->toBe(ListingSortField::Published)
        ->and($archive->filter->sortAscending)->toBeTrue()
        ->and($archive->stop_policy)->toBe(RunStopPolicy::Default);

    $fresh = $crawlers[CrawlerMode::Fresh->value];
    expect($fresh->filter->sortField)->toBe(ListingSortField::Updated)
        ->and($fresh->filter->sortAscending)->toBeFalse()
        ->and($fresh->stop_policy)->toBe(RunStopPolicy::OnlyFresh);
});

test('повторный прогон сида профилей идемпотентен — число профилей не меняется', function (): void {
    $this->seed(EisPlatformSeeder::class);
    $this->seed(CrawlerProfileSeeder::class);
    $this->seed(CrawlerProfileSeeder::class);

    $registry = Registry::query()->where('code', 'fz44')->sole();

    expect(Crawler::query()->where('registry_id', $registry->id)->count())->toBe(2);
});

test('сид профилей без площадки eis бросает внятное исключение', function (): void {
    expect(fn () => (new CrawlerProfileSeeder)->run())
        ->toThrow(RuntimeException::class, 'eis');
});

/**
 * Д-2: реестр `fz44` привязывается по паре (площадка, код), а не по голому коду — код НЕ
 * уникален глобально (фабрики заводят реестры `fz44` у сторонних площадок). Без фикса профили
 * достаются первому попавшемуся реестру `fz44` вместо реестра площадки `eis`.
 */
test('сид профилей резолвит реестр fz44 СВОЕЙ площадки eis, а не чужой', function (): void {
    Registry::factory()->create(['code' => 'fz44']);

    $this->seed(EisPlatformSeeder::class);
    $this->seed(CrawlerProfileSeeder::class);

    $eisPlatform = Platform::query()->where('code', 'eis')->sole();
    $eisRegistry = Registry::query()->where('platform_id', $eisPlatform->id)->where('code', 'fz44')->sole();

    $crawlers = Crawler::query()->where('registry_id', $eisRegistry->id)->get();

    expect($crawlers)->toHaveCount(2);
});

test('архивный профиль из сида даёт ожидаемый боевой URL выгрузки', function (): void {
    $this->seed(EisPlatformSeeder::class);
    $this->seed(Okpd2NodeSeeder::class);
    $this->seed(CrawlerProfileSeeder::class);

    $registry = Registry::query()->where('code', 'fz44')->sole();
    $archive = Crawler::query()
        ->where('registry_id', $registry->id)
        ->where('mode', CrawlerMode::Archive)
        ->sole();

    $query = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $archive, from: 1, to: 500);

    expect($query)
        ->toHaveKey('pc', 'on')
        ->toHaveKey('registryNumberCsv', 'on')
        ->toHaveKey('publishDateCsv', 'on')
        ->toHaveKey('lastDateChangeCsv', 'on')
        ->toHaveKey('fz44', 'on')
        ->and($query)->not->toHaveKey('af');
});

test('сидовый архивный профиль не передаёт окно публикации в sweep по дате обновления', function (): void {
    $this->seed(EisPlatformSeeder::class);
    $this->seed(Okpd2NodeSeeder::class);
    $this->seed(CrawlerProfileSeeder::class);

    $registry = Registry::query()->where('code', 'fz44')->sole();
    $archive = Crawler::query()
        ->where('registry_id', $registry->id)
        ->where('mode', CrawlerMode::Archive)
        ->sole();

    $query = app(EisCsvUrlBuilder::class)->buildQueryParams(
        crawler: $archive,
        from: 1,
        to: 500,
        cursor: new ListingCursor(blockFrom: 1, day: '2026-08-08', priceStack: ['0:500000'], dateField: 'update'),
    );

    expect($query['updateDateFrom'])->toBe('08.08.2026')
        ->and($query['updateDateTo'])->toBe('08.08.2026')
        ->and($query)->not->toHaveKey('publishDateFrom')
        ->and($query)->not->toHaveKey('publishDateTo');
});
