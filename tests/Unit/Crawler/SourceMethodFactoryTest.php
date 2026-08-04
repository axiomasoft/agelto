<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Exceptions\SourceMethodUnavailableException;
use App\Services\Procurement\SourceMethodFactory;
use Tests\Support\Doubles\Crawler\FakeAvailableSourceMethod;
use Tests\Support\Doubles\Crawler\FakeUnavailableSourceMethod;

test('make резолвит реализацию из карты config crawler.source_methods', function () {
    config(['crawler.source_methods' => [
        SourceMethodEnum::Csv->value => FakeAvailableSourceMethod::class,
    ]]);

    $method = app(SourceMethodFactory::class)->make(method: SourceMethodEnum::Csv);

    expect($method)->toBeInstanceOf(FakeAvailableSourceMethod::class);
});

test('makeWithFallback переключается на fallback когда primary недоступен', function () {
    config(['crawler.source_methods' => [
        SourceMethodEnum::Csv->value => FakeUnavailableSourceMethod::class,
        SourceMethodEnum::Html->value => FakeAvailableSourceMethod::class,
    ]]);

    $crawler = new Crawler;
    $crawler->source_method = SourceMethodEnum::Csv;
    $crawler->fallback_method = SourceMethodEnum::Html;

    $method = app(SourceMethodFactory::class)->makeWithFallback(crawler: $crawler);

    expect($method)->toBeInstanceOf(FakeAvailableSourceMethod::class);
});

test('makeWithFallback кидает исключение когда primary и fallback недоступны', function () {
    config(['crawler.source_methods' => [
        SourceMethodEnum::Csv->value => FakeUnavailableSourceMethod::class,
        SourceMethodEnum::Html->value => FakeUnavailableSourceMethod::class,
    ]]);

    $crawler = new Crawler;
    $crawler->source_method = SourceMethodEnum::Csv;
    $crawler->fallback_method = SourceMethodEnum::Html;

    app(SourceMethodFactory::class)->makeWithFallback(crawler: $crawler);
})->throws(SourceMethodUnavailableException::class);

test('makeWithFallback кидает исключение когда primary недоступен и fallback не задан', function () {
    config(['crawler.source_methods' => [
        SourceMethodEnum::Csv->value => FakeUnavailableSourceMethod::class,
    ]]);

    $crawler = new Crawler;
    $crawler->source_method = SourceMethodEnum::Csv;
    $crawler->fallback_method = null;

    app(SourceMethodFactory::class)->makeWithFallback(crawler: $crawler);
})->throws(SourceMethodUnavailableException::class);

test('make кидает исключение когда метод не замаплен в config crawler.source_methods', function () {
    config(['crawler.source_methods' => []]);

    app(SourceMethodFactory::class)->make(method: SourceMethodEnum::Html);
})->throws(SourceMethodUnavailableException::class);

test('makeWithFallback кидает исключение когда fallback не замаплен в config crawler.source_methods', function () {
    config(['crawler.source_methods' => [
        SourceMethodEnum::Csv->value => FakeUnavailableSourceMethod::class,
    ]]);

    $crawler = new Crawler;
    $crawler->source_method = SourceMethodEnum::Csv;
    $crawler->fallback_method = SourceMethodEnum::Html;

    app(SourceMethodFactory::class)->makeWithFallback(crawler: $crawler);
})->throws(SourceMethodUnavailableException::class);
