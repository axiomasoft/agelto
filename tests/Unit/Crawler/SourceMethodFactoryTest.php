<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Exceptions\Procurement\Crawler\PlatformAdapterUnavailableException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Exceptions\SourceMethodUnavailableException;
use App\Services\Procurement\SourceMethodFactory;
use Tests\Support\Doubles\Crawler\FakeAvailableSourceMethod;
use Tests\Support\Doubles\Crawler\FakeCrawlerPlatformForm;
use Tests\Support\Doubles\Crawler\FakeUnavailableSourceMethod;

function sourceMethodRegistry(): Registry
{
    $platform = new Platform;
    $platform->code = 'test';

    $registry = new Registry;
    $registry->code = 'main';
    $registry->setRelation('platform', $platform);

    return $registry;
}

function sourceMethodPlatformConfig(array $sourceMethods): array
{
    return [
        'test' => [
            'source_methods' => $sourceMethods,
            'registry_filters' => [
                'main' => [
                    'filter' => EisSearchFilter::class,
                    'form' => FakeCrawlerPlatformForm::class,
                ],
            ],
        ],
    ];
}

test('make резолвит реализацию из exact platform definition', function () {
    config(['crawler.platforms' => sourceMethodPlatformConfig([
        SourceMethodEnum::Csv->value => FakeAvailableSourceMethod::class,
    ])]);

    $method = app(SourceMethodFactory::class)->make(method: SourceMethodEnum::Csv, registry: sourceMethodRegistry());

    expect($method)->toBeInstanceOf(FakeAvailableSourceMethod::class);
});

test('makeWithFallback переключается на fallback когда primary недоступен', function () {
    config(['crawler.platforms' => sourceMethodPlatformConfig([
        SourceMethodEnum::Csv->value => FakeUnavailableSourceMethod::class,
        SourceMethodEnum::Html->value => FakeAvailableSourceMethod::class,
    ])]);

    $crawler = new Crawler;
    $crawler->source_method = SourceMethodEnum::Csv;
    $crawler->fallback_method = SourceMethodEnum::Html;
    $crawler->setRelation('registry', sourceMethodRegistry());

    $method = app(SourceMethodFactory::class)->makeWithFallback(crawler: $crawler);

    expect($method)->toBeInstanceOf(FakeAvailableSourceMethod::class);
});

test('makeWithFallback кидает исключение когда primary и fallback недоступны', function () {
    config(['crawler.platforms' => sourceMethodPlatformConfig([
        SourceMethodEnum::Csv->value => FakeUnavailableSourceMethod::class,
        SourceMethodEnum::Html->value => FakeUnavailableSourceMethod::class,
    ])]);

    $crawler = new Crawler;
    $crawler->source_method = SourceMethodEnum::Csv;
    $crawler->fallback_method = SourceMethodEnum::Html;
    $crawler->setRelation('registry', sourceMethodRegistry());

    app(SourceMethodFactory::class)->makeWithFallback(crawler: $crawler);
})->throws(SourceMethodUnavailableException::class);

test('makeWithFallback кидает исключение когда primary недоступен и fallback не задан', function () {
    config(['crawler.platforms' => sourceMethodPlatformConfig([
        SourceMethodEnum::Csv->value => FakeUnavailableSourceMethod::class,
    ])]);

    $crawler = new Crawler;
    $crawler->source_method = SourceMethodEnum::Csv;
    $crawler->fallback_method = null;
    $crawler->setRelation('registry', sourceMethodRegistry());

    app(SourceMethodFactory::class)->makeWithFallback(crawler: $crawler);
})->throws(SourceMethodUnavailableException::class);

test('make кидает fail-closed исключение когда метод не замаплен в platform definition', function () {
    config(['crawler.platforms' => sourceMethodPlatformConfig([])]);

    app(SourceMethodFactory::class)->make(method: SourceMethodEnum::Html, registry: sourceMethodRegistry());
})->throws(PlatformAdapterUnavailableException::class);

test('makeWithFallback кидает fail-closed исключение когда fallback не замаплен в platform definition', function () {
    config(['crawler.platforms' => sourceMethodPlatformConfig([
        SourceMethodEnum::Csv->value => FakeUnavailableSourceMethod::class,
    ])]);

    $crawler = new Crawler;
    $crawler->source_method = SourceMethodEnum::Csv;
    $crawler->fallback_method = SourceMethodEnum::Html;
    $crawler->setRelation('registry', sourceMethodRegistry());

    app(SourceMethodFactory::class)->makeWithFallback(crawler: $crawler);
})->throws(PlatformAdapterUnavailableException::class);
