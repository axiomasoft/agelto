<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Services\Procurement\Eis\EisCsvUrlBuilder;
use Tests\Support\Doubles\Crawler\CrawlerWithRegistry;

test('buildQueryParams переносит filter как строки и добавляет from/to/sort', function () {
    $crawler = CrawlerWithRegistry::make(
        registryCode: 'fz44',
        filter: ['okpd2' => '86.10', 'priceFrom' => 100000],
        options: null,
    );

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['okpd2'])->toBe('86.10')
        ->and($params['priceFrom'])->toBe('100000')
        ->and($params['from'])->toBe('1')
        ->and($params['to'])->toBe('500')
        ->and($params['sortBy'])->toBe('UPDATE_DATE')
        ->and($params['sortDirection'])->toBe('false');
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
    $crawler = CrawlerWithRegistry::make(registryCode: 'fz44', filter: null, options: null);

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['fz44'])->toBe('on')
        ->and($params)->not->toHaveKey('fz223');
});

test('buildQueryParams выставляет флаг реестра fz223=on по crawler.registry.code', function () {
    $crawler = CrawlerWithRegistry::make(registryCode: 'fz223', filter: null, options: null);

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

    expect($params['fz223'])->toBe('on')
        ->and($params)->not->toHaveKey('fz44');
});

test('buildQueryParams без filter и без options.columns собирает только from/to/registry/sort/registryNumberCsv', function () {
    $crawler = CrawlerWithRegistry::make(registryCode: 'fz44', filter: null, options: null);

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
    $crawler = CrawlerWithRegistry::make(registryCode: 'fz44', filter: null, options: null);
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
    $crawler = CrawlerWithRegistry::make(registryCode: 'fz44', filter: null, options: null);
    $cursor = new ListingCursor(blockFrom: 1, day: '2024-03-15', priceStack: ['100:200'], dateField: 'update');

    $params = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500, cursor: $cursor);

    expect($params['updateDateFrom'])->toBe('15.03.2024')
        ->and($params['updateDateTo'])->toBe('15.03.2024')
        ->and($params)->not->toHaveKey('publishDateFrom');
});

test('buildQueryParams без cursor или с cursor->day=null — прежний fresh-набор без регресса', function () {
    $crawler = CrawlerWithRegistry::make(registryCode: 'fz44', filter: null, options: null);

    $withoutCursor = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);
    $withNullDayCursor = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500, cursor: new ListingCursor);

    expect($withoutCursor)->toBe($withNullDayCursor)
        ->and($withoutCursor['sortDirection'])->toBe('false')
        ->and($withoutCursor)->not->toHaveKey('publishDateFrom');
});
