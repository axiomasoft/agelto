<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use Database\Seeders\Platforms\Eis\EisCrawlerProfileSeeder;
use Database\Seeders\Platforms\Eis\EisPlatformSeeder;
use Database\Seeders\Procurement\Okpd2NodeSeeder;
use Database\Seeders\StatusMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;

uses(RefreshDatabase::class);

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

    $platform = Platform::query()->where('code', 'eis')->sole();
    $registry = Registry::query()->where('platform_id', $platform->id)->where('code', 'fz44')->sole();
    $registryId = $registry->id;
    $registryUuid = $registry->uuid;

    $this->seed(EisPlatformSeeder::class);

    $reseededRegistry = Registry::query()
        ->where('platform_id', $platform->id)
        ->where('code', 'fz44')
        ->sole();

    expect(Platform::query()->where('code', 'eis')->count())->toBe(1)
        ->and(Registry::query()->where('platform_id', $platform->id)->where('code', 'fz44')->count())->toBe(1)
        ->and($reseededRegistry->id)->toBe($registryId)
        ->and($reseededRegistry->uuid)->toBe($registryUuid);
});

test('после сида площадки StatusMappingSeeder заводит матрицу без предупреждения', function (): void {
    $this->seed(EisPlatformSeeder::class);

    $this->seed(StatusMappingSeeder::class);

    $platform = Platform::query()->where('code', 'eis')->sole();
    $registry = Registry::query()->where('platform_id', $platform->id)->where('code', 'fz44')->sole();

    expect(StatusMapping::query()->where('registry_id', $registry->id)->count())
        ->toBe(5);
});

test('сид заводит два профиля обхода — архивный активен, fresh выключен', function (): void {
    $this->seed(EisPlatformSeeder::class);
    $this->seed(EisCrawlerProfileSeeder::class);

    $platform = Platform::query()->where('code', 'eis')->sole();
    $registry = Registry::query()->where('platform_id', $platform->id)->where('code', 'fz44')->sole();
    $crawlers = Crawler::query()->where('registry_id', $registry->id)->get()->keyBy('name');

    expect($crawlers)->toHaveCount(3);

    $archive2025 = $crawlers['ЕИС 44-ФЗ — архив 2025 (завершённые)'];
    expect($archive2025->is_active)->toBeTrue()
        ->and($archive2025->route_profile_id)->toBeNull()
        ->and($archive2025->filter->stageCompleted)->toBeTrue()
        ->and($archive2025->filter->okpd2Codes)->toBe(['41', '42', '43'])
        ->and($archive2025->filter->priceFrom)->toBe(100_000)
        ->and($archive2025->filter->priceTo)->toBeNull()
        ->and(Schema::hasColumn('prc_crawlers', 'execution_lane'))->toBeFalse();

    $archive2026 = $crawlers['ЕИС 44-ФЗ — архив 2026 (завершённые)'];
    expect($archive2026->is_active)->toBeTrue()
        ->and($archive2026->route_profile_id)->toBeNull()
        ->and($archive2026->filter->stageCompleted)->toBeTrue()
        ->and($archive2026->filter->okpd2Codes)->toBe(['41', '42', '43'])
        ->and($archive2026->filter->priceFrom)->toBe(100_000)
        ->and($archive2026->filter->priceTo)->toBeNull();

    $fresh = $crawlers['ЕИС 44-ФЗ — свежий поток'];
    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->route_profile_id)->toBeNull()
        ->and($fresh->filter->okpd2Codes)->toBe(['41', '42', '43'])
        ->and($fresh->filter->priceFrom)->toBe(100_000)
        ->and($fresh->filter->priceTo)->toBeNull()
        ->and($fresh->schedule)->toBe('*/30 * * * *');
});

test('сид заводит архивный профиль с окном дат прогона', function (): void {
    $this->seed(EisPlatformSeeder::class);
    $this->seed(EisCrawlerProfileSeeder::class);

    $platform = Platform::query()->where('code', 'eis')->sole();
    $registry = Registry::query()->where('platform_id', $platform->id)->where('code', 'fz44')->sole();
    $archive2025 = Crawler::query()->where('registry_id', $registry->id)->where('name', 'ЕИС 44-ФЗ — архив 2025 (завершённые)')->sole();
    $archive2026 = Crawler::query()->where('registry_id', $registry->id)->where('name', 'ЕИС 44-ФЗ — архив 2026 (завершённые)')->sole();

    expect($archive2025->filter->archiveWindowFrom()?->toDateString())->toBe('2025-01-01')
        ->and($archive2025->filter->archiveWindowTo()?->toDateString())->toBe('2025-12-31')
        ->and($archive2026->filter->archiveWindowFrom()?->toDateString())->toBe('2026-01-01')
        ->and($archive2026->filter->archiveWindowTo()?->toDateString())->toBe('2026-12-31');
});

/**
 * поля сортировки и `stop_policy` — единственная проверка, что заведённые фазами /* поля совместимы друг с другом (архивный профиль сортирует по возрастанию даты размещения без
 * стоп-условия, fresh — по убыванию даты обновления со стоп-условием «только актуальные данные»).
 */
test('сид заводит профили с сортировкой выдачи и политикой остановки из брифа', function (): void {
    $this->seed(EisPlatformSeeder::class);
    $this->seed(EisCrawlerProfileSeeder::class);

    $platform = Platform::query()->where('code', 'eis')->sole();
    $registry = Registry::query()->where('platform_id', $platform->id)->where('code', 'fz44')->sole();
    $crawlers = Crawler::query()->where('registry_id', $registry->id)->get()->keyBy('name');

    $archive = $crawlers['ЕИС 44-ФЗ — архив 2025 (завершённые)'];
    expect($archive->filter->sortField)->toBe(ListingSortField::Published)
        ->and($archive->filter->sortAscending)->toBeTrue()
        ->and(Schema::hasColumn('prc_crawlers', 'stop_policy'))->toBeFalse();

    $fresh = $crawlers['ЕИС 44-ФЗ — свежий поток'];
    expect($fresh->filter->sortField)->toBe(ListingSortField::Updated)
        ->and($fresh->filter->sortAscending)->toBeFalse()
        ->and($fresh->listing_kind)->toBe(AcquisitionKind::Fresh);
});

test('повторный прогон сида профилей идемпотентен — число профилей не меняется', function (): void {
    $this->seed(EisPlatformSeeder::class);
    $this->seed(EisCrawlerProfileSeeder::class);
    $this->seed(EisCrawlerProfileSeeder::class);

    $platform = Platform::query()->where('code', 'eis')->sole();
    $registry = Registry::query()->where('platform_id', $platform->id)->where('code', 'fz44')->sole();

    expect(Crawler::query()->where('registry_id', $registry->id)->count())->toBe(3);
});

test('сид профилей без площадки eis бросает внятное исключение', function (): void {
    expect(fn () => (new EisCrawlerProfileSeeder)->run())
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
    $this->seed(EisCrawlerProfileSeeder::class);

    $eisPlatform = Platform::query()->where('code', 'eis')->sole();
    $eisRegistry = Registry::query()->where('platform_id', $eisPlatform->id)->where('code', 'fz44')->sole();

    $crawlers = Crawler::query()->where('registry_id', $eisRegistry->id)->get();

    expect($crawlers)->toHaveCount(3);
});

test('архивный профиль из сида даёт ожидаемый боевой URL выгрузки', function (): void {
    $this->seed(EisPlatformSeeder::class);
    $this->seed(Okpd2NodeSeeder::class);
    $this->seed(EisCrawlerProfileSeeder::class);

    $platform = Platform::query()->where('code', 'eis')->sole();
    $registry = Registry::query()->where('platform_id', $platform->id)->where('code', 'fz44')->sole();
    $archive = Crawler::query()
        ->where('registry_id', $registry->id)
        ->where('name', 'ЕИС 44-ФЗ — архив 2025 (завершённые)')
        ->sole();

    $query = AcquisitionProfileFixtures::query($archive);

    expect($query)
        ->toHaveKey('pc', 'on')
        ->toHaveKey('okpd2Ids', '8873919,8873920,8873921')
        ->toHaveKey('okpd2IdsWithNested', 'on')
        ->toHaveKey('priceFromGeneral', '100000.00')
        ->toHaveKey('registryNumberCsv', 'on')
        ->toHaveKey('publishDateCsv', 'on')
        ->toHaveKey('lastDateChangeCsv', 'on')
        ->toHaveKey('fz44', 'on')
        ->and($query)->not->toHaveKey('priceToGeneral')
        ->and($query)->not->toHaveKey('af');
});

test('сидовый архивный профиль не передаёт окно публикации в sweep по дате обновления', function (): void {
    $this->seed(EisPlatformSeeder::class);
    $this->seed(Okpd2NodeSeeder::class);
    $this->seed(EisCrawlerProfileSeeder::class);

    $platform = Platform::query()->where('code', 'eis')->sole();
    $registry = Registry::query()->where('platform_id', $platform->id)->where('code', 'fz44')->sole();
    $archive = Crawler::query()
        ->where('registry_id', $registry->id)
        ->where('name', 'ЕИС 44-ФЗ — архив 2025 (завершённые)')
        ->sole();

    $query = AcquisitionProfileFixtures::query($archive, AcquisitionKind::Delta, '2026-08-08');

    expect($query['updateDateFrom'])->toBe('08.08.2026')
        ->and($query['updateDateTo'])->toBe('08.08.2026')
        ->and($query)->not->toHaveKey('publishDateFrom')
        ->and($query)->not->toHaveKey('publishDateTo');
});
