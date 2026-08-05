<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\CrawlerFilterFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('filter профиля читается типизированным объектом, а не мешком ключей', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create([
        'filter' => ['stageCompleted' => true, 'priceFrom' => 100000, 'publishedFrom' => '2025-03-15'],
    ]);

    $filter = $crawler->fresh()->filter;

    expect($filter)->toBeInstanceOf(EisSearchFilter::class)
        ->and($filter->stageCompleted)->toBeTrue()
        ->and($filter->stageApplications)->toBeFalse()
        ->and($filter->priceFrom)->toBe(100000)
        ->and($filter->publishedFrom)->toBeInstanceOf(CarbonImmutable::class)
        ->and($filter->publishedFrom->toDateString())->toBe('2025-03-15');
});

test('filter хранится прежним jsonb — в БД лежат ключи контракта, а не сериализованный объект', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create([
        'filter' => new EisSearchFilter(stageCompleted: true),
    ]);

    $stored = json_decode(
        (string) DB::table('prc_crawlers')->where('id', $crawler->getKey())->value('filter'),
        associative: true,
    );

    expect($stored['stageCompleted'])->toBeTrue()
        ->and($stored)->toHaveKey('priceFrom');
});

test('неизвестный ключ фильтра отклоняется на сохранении профиля с именем ключа и списком допустимых', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);

    expect(fn () => Crawler::factory()->for($registry)->create(['filter' => ['okpd2' => '86.10']]))
        ->toThrow(function (ValidationException $exception) {
            expect($exception->getMessage())->toContain('okpd2')
                ->and($exception->getMessage())->toContain('stageCompleted');
        });
});

test('priceTo=0 отклоняется валидацией — он обнуляет выдачу ЕИС, а не снимает ограничение', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);

    expect(fn () => Crawler::factory()->for($registry)->create(['filter' => ['priceTo' => 0]]))
        ->toThrow(ValidationException::class);
});

test('пустой filter остаётся null и класс фильтра не резолвится', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create(['filter' => null]);

    expect($crawler->fresh()->filter)->toBeNull();
});

test('класс фильтра резолвится по коду реестра через карту config(crawler.filters)', function () {
    $registry = new Registry;
    $registry->code = 'fz44';

    expect(app(CrawlerFilterFactory::class)->for(registry: $registry))->toBe(EisSearchFilter::class);
});

test('реестр без записи в карте фильтров даёт исключение с кодом реестра', function () {
    $registry = new Registry;
    $registry->code = 'fz223';

    expect(fn () => app(CrawlerFilterFactory::class)->for(registry: $registry))
        ->toThrow(RuntimeException::class, 'fz223');
});
