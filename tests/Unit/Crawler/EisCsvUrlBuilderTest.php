<?php

declare(strict_types=1);

use App\Services\Crawler\Eis\EisCsvUrlBuilder;
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
