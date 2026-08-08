<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\Eis\EisCsvUrlBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Doubles\Crawler\CrawlerWithRegistry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->platformId = Platform::factory()->create()->id;

    Okpd2Node::factory()->create(['platform_id' => $this->platformId, 'code' => '43', 'external_id' => '8873921']);
});

test('buildQueryParams переводит поля типизированного фильтра в параметры ЕИС и добавляет from/to/sort', function () {
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['stageCompleted' => true, 'priceFrom' => 100000, 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['pc'])->toBe('on')
        ->and($params['priceFromGeneral'])->toBe('100000')
        ->and($params['from'])->toBe('1')
        ->and($params['to'])->toBe('500')
        ->and($params['sortBy'])->toBe('UPDATE_DATE')
        ->and($params['sortDirection'])->toBe('false');
});

test('buildQueryParams не эмитит ключ выключенного этапа и незаполненного поля', function () {
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['stageCompleted' => true, 'stageApplications' => false, 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params)->toHaveKey('pc')
        ->and($params)->not->toHaveKey('af')
        ->and($params)->not->toHaveKey('ca')
        ->and($params)->not->toHaveKey('pa')
        ->and($params)->not->toHaveKey('morphology')
        ->and($params)->not->toHaveKey('priceFromGeneral')
        ->and($params)->not->toHaveKey('priceToGeneral')
        ->and($params)->not->toHaveKey('publishDateFrom')
        ->and($params)->not->toHaveKey('searchString');
});

test('buildQueryParams форматирует даты фильтра в d.m.Y, а типизированное значение остаётся в DTO', function () {
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['publishedFrom' => '2025-03-15', 'publishedTo' => '2025-03-31', 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['publishDateFrom'])->toBe('15.03.2025')
        ->and($params['publishDateTo'])->toBe('31.03.2025');
});

test('buildQueryParams переносит searchString и morphology как есть', function () {
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['searchString' => 'ремонт кровли', 'morphology' => true, 'priceTo' => 5000000, 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['searchString'])->toBe('ремонт кровли')
        ->and($params['morphology'])->toBe('on')
        ->and($params['priceToGeneral'])->toBe('5000000');
});

test('buildQueryParams archive-ветка перекрывает даты фильтра окном сканера', function () {
    config(['crawler.archive.date_format' => 'd.m.Y']);
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['publishedFrom' => '2025-03-15', 'publishedTo' => '2025-03-31', 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );
    $cursor = new ListingCursor(blockFrom: 1, day: '2024-03-15', priceStack: ['0:500000']);

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500, cursor: $cursor);

    expect($params['publishDateFrom'])->toBe('15.03.2024')
        ->and($params['publishDateTo'])->toBe('15.03.2024');
});

test('buildQueryParams добавляет column=on только для true-колонок options.columns', function () {
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: null,
        options: ['columns' => ['price' => true, 'region' => true, 'stage' => false]],
    );

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['price'])->toBe('on')
        ->and($params['region'])->toBe('on')
        ->and($params)->not->toHaveKey('stage');
});

test('buildQueryParams выставляет флаг реестра fz44=on по crawler.registry.code', function () {
    $crawler = CrawlerWithRegistry::make(registryCode: 'fz44', filter: null, options: null, platformId: $this->platformId);

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['fz44'])->toBe('on')
        ->and($params)->not->toHaveKey('fz223');
});

test('buildQueryParams выставляет флаг реестра fz223=on по crawler.registry.code', function () {
    $crawler = CrawlerWithRegistry::make(registryCode: 'fz223', filter: null, options: null, platformId: $this->platformId);

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['fz223'])->toBe('on')
        ->and($params)->not->toHaveKey('fz44');
});

test('buildQueryParams без filter и без options.columns собирает только from/to/registry/sort/registryNumberCsv', function () {
    $crawler = CrawlerWithRegistry::make(registryCode: 'fz44', filter: null, options: null, platformId: $this->platformId);

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 501, to: 1000);

    expect($params)->toBe([
        'from' => '501',
        'to' => '1000',
        'fz44' => 'on',
        'sortBy' => 'UPDATE_DATE',
        'sortDirection' => 'false',
        'registryNumberCsv' => 'on',
    ]);
});

test('buildQueryParams всегда запрашивает registryNumberCsv даже если он выключен в options.columns', function () {
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: null,
        options: ['columns' => ['registryNumberCsv' => false]],
    );

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['registryNumberCsv'])->toBe('on');
});

test('buildQueryParams с cursor->day archive-ветка добавляет publishDateFrom/To+price+sortDirection=true', function () {
    config(['crawler.archive.date_format' => 'd.m.Y']);
    $crawler = CrawlerWithRegistry::make(registryCode: 'fz44', filter: null, options: null, platformId: $this->platformId);
    $cursor = new ListingCursor(blockFrom: 1, day: '2024-03-15', priceStack: ['0:500000']);

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500, cursor: $cursor);

    expect($params['publishDateFrom'])->toBe('15.03.2024')
        ->and($params['publishDateTo'])->toBe('15.03.2024')
        ->and($params['priceFromGeneral'])->toBe('0')
        ->and($params['priceToGeneral'])->toBe('500000')
        ->and($params['sortDirection'])->toBe('true')
        ->and($params)->not->toHaveKey('updateDateFrom');
});

test('buildQueryParams с cursor->day и cursor->dateField=update маппится на updateDateFrom/To', function () {
    $crawler = CrawlerWithRegistry::make(registryCode: 'fz44', filter: null, options: null, platformId: $this->platformId);
    $cursor = new ListingCursor(blockFrom: 1, day: '2024-03-15', priceStack: ['100:200'], dateField: 'update');

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500, cursor: $cursor);

    expect($params['updateDateFrom'])->toBe('15.03.2024')
        ->and($params['updateDateTo'])->toBe('15.03.2024')
        ->and($params)->not->toHaveKey('publishDateFrom');
});

test('buildQueryParams с окном профиля и update-курсором снимает окно публикации', function () {
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['publishedFrom' => '2025-01-01', 'publishedTo' => '2025-12-31', 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );
    $cursor = new ListingCursor(blockFrom: 1, day: '2026-08-08', priceStack: ['100:200'], dateField: 'update');

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500, cursor: $cursor);

    expect($params['updateDateFrom'])->toBe('08.08.2026')
        ->and($params['updateDateTo'])->toBe('08.08.2026')
        ->and($params)->not->toHaveKey('publishDateFrom')
        ->and($params)->not->toHaveKey('publishDateTo');
});

test('buildQueryParams с окном профиля и publish-курсором сохраняет archive-параметры', function () {
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['publishedFrom' => '2025-01-01', 'publishedTo' => '2025-12-31', 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );
    $cursor = new ListingCursor(blockFrom: 1, day: '2025-06-15', priceStack: ['100:200'], dateField: 'publish');

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500, cursor: $cursor);

    expect($params['publishDateFrom'])->toBe('15.06.2025')
        ->and($params['publishDateTo'])->toBe('15.06.2025')
        ->and($params['priceFromGeneral'])->toBe('100')
        ->and($params['priceToGeneral'])->toBe('200')
        ->and($params['sortDirection'])->toBe('true')
        ->and($params)->not->toHaveKey('updateDateFrom')
        ->and($params)->not->toHaveKey('updateDateTo');
});

test('buildQueryParams без cursor или с cursor->day=null — прежний fresh-набор без регресса', function () {
    $crawler = CrawlerWithRegistry::make(registryCode: 'fz44', filter: null, options: null, platformId: $this->platformId);

    $withoutCursor = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);
    $withNullDayCursor = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500, cursor: new ListingCursor);

    expect($withoutCursor)->toBe($withNullDayCursor)
        ->and($withoutCursor['sortDirection'])->toBe('false')
        ->and($withoutCursor)->not->toHaveKey('publishDateFrom');
});

test('buildQueryParams резолвит okpd2Codes в okpd2Ids по коду и добавляет okpd2IdsWithNested=on', function () {
    Okpd2Node::factory()->create(['platform_id' => $this->platformId, 'code' => '41', 'external_id' => '8873919']);
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['okpd2Codes' => ['43', '41']],
        options: null,
        platformId: $this->platformId,
    );

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['okpd2Ids'])->toBe('8873919,8873921')
        ->and($params['okpd2IdsWithNested'])->toBe('on');
});

test('buildQueryParams выбор одного листового кода тоже добавляет okpd2IdsWithNested=on', function () {
    Okpd2Node::factory()->create(['platform_id' => $this->platformId, 'code' => '43.21.10.140', 'external_id' => '9000001']);
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['okpd2Codes' => ['43.21.10.140']],
        options: null,
        platformId: $this->platformId,
    );

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['okpd2Ids'])->toBe('9000001')
        ->and($params['okpd2IdsWithNested'])->toBe('on');
});

test('buildQueryParams порядок okpd2Ids не зависит от порядка кодов в профиле', function () {
    Okpd2Node::factory()->create(['platform_id' => $this->platformId, 'code' => '41', 'external_id' => '8873919']);
    Okpd2Node::factory()->create(['platform_id' => $this->platformId, 'code' => '42', 'external_id' => '8873920']);

    $forward = CrawlerWithRegistry::make(registryCode: 'fz44', filter: ['okpd2Codes' => ['41', '42', '43']], options: null, platformId: $this->platformId);
    $reversed = CrawlerWithRegistry::make(registryCode: 'fz44', filter: ['okpd2Codes' => ['43', '42', '41']], options: null, platformId: $this->platformId);

    $paramsForward = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $forward, from: 1, to: 500);
    $paramsReversed = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $reversed, from: 1, to: 500);

    expect($paramsForward['okpd2Ids'])->toBe('8873919,8873920,8873921')
        ->and($paramsReversed['okpd2Ids'])->toBe($paramsForward['okpd2Ids']);
});

test('buildQueryParams на нерезолвящемся коде ОКПД2 отказывает исключением с именем кода', function () {
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['okpd2Codes' => ['43', '99.99']],
        options: null,
        platformId: $this->platformId,
    );

    expect(fn () => app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500))
        ->toThrow(RuntimeException::class, '99.99');
});

test('buildQueryParams переводит поле сортировки профиля в sortBy для обоих значений', function () {
    $updated = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['sortField' => 'updated', 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );
    $published = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['sortField' => 'published', 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );

    $updatedParams = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $updated, from: 1, to: 500);
    $publishedParams = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $published, from: 1, to: 500);

    expect($updatedParams['sortBy'])->toBe('UPDATE_DATE')
        ->and($publishedParams['sortBy'])->toBe('PUBLISH_DATE');
});

test('buildQueryParams переводит sortAscending профиля в sortDirection для обоих направлений (инверсия)', function () {
    $ascending = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['sortField' => 'updated', 'sortAscending' => true, 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );
    $descending = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['sortField' => 'updated', 'sortAscending' => false, 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );

    $ascendingParams = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $ascending, from: 1, to: 500);
    $descendingParams = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $descending, from: 1, to: 500);

    // sortDirection инвертирован площадкой: 'true' — возрастание, 'false' — убывание
    // (findings/eis-params-carryover.md §1) — интуиция здесь врёт, проверяем ТОЧНУЮ строку.
    expect($ascendingParams['sortDirection'])->toBe('true')
        ->and($descendingParams['sortDirection'])->toBe('false');
});

test('buildQueryParams профиль без поля сортировки даёт прежнюю пару sortBy/sortDirection', function () {
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['stageCompleted' => true, 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['sortBy'])->toBe('UPDATE_DATE')
        ->and($params['sortDirection'])->toBe('false');
});

test('buildQueryParams archive-курсор перекрывает sortDirection профиля безусловно', function () {
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['sortField' => 'updated', 'sortAscending' => false, 'okpd2Codes' => ['43']],
        options: null,
        platformId: $this->platformId,
    );
    $cursor = new ListingCursor(blockFrom: 1, day: '2024-03-15', priceStack: ['0:500000']);

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500, cursor: $cursor);

    // Профиль просит убывание (sortAscending=false), но archive-ветка ставит порядок
    // сама (D21) — направление сканера обязано выигрывать у профиля.
    expect($params['sortDirection'])->toBe('true');
});
