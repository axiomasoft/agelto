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
        'filter' => [
            'stageCompleted' => true,
            'priceFrom' => 100000,
            'publishedFrom' => '2025-03-15',
            'okpd2Codes' => ['43'],
        ],
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
        'filter' => new EisSearchFilter(stageCompleted: true, okpd2Codes: ['43']),
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

    // Строка-нарушитель заведомо чужая: `okpd2Codes` теперь ВАЛИДЕН, и ключ, отличающийся от него
    // одним суффиксом, сделал бы тест хрупким (research/03-p2-okpd2-design.md §2.4).
    expect(fn () => Crawler::factory()->for($registry)->create(
        ['filter' => ['stageUnknown' => true, 'okpd2Codes' => ['43']]]
    ))
        ->toThrow(function (ValidationException $exception) {
            expect($exception->getMessage())->toContain('stageUnknown')
                ->and($exception->getMessage())->toContain('stageCompleted');
        });
});

test('priceTo=0 отклоняется валидацией — он обнуляет выдачу ЕИС, а не снимает ограничение', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);

    expect(fn () => Crawler::factory()->for($registry)->create(
        ['filter' => ['priceTo' => 0, 'okpd2Codes' => ['43']]]
    ))->toThrow(ValidationException::class);
});

test('пустой filter остаётся null там, где реестр не требует скоупа, и класс фильтра не резолвится', function () {
    // Реестра `fz223` нет в `config('crawler.filters')` — требования скоупа для него не объявлено,
    // поэтому профиль без фильтра легален. У `fz44` тот же случай теперь отвергается (D18) —
    // отдельный тест ниже.
    $registry = Registry::factory()->create(['code' => 'fz223']);
    $crawler = Crawler::factory()->for($registry)->create(['filter' => null]);

    expect($crawler->fresh()->filter)->toBeNull();
});

test('коды ОКПД2 доезжают до объекта фильтра списком строк', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create([
        'filter' => ['okpd2Codes' => ['43', '43.29', 'F']],
    ]);

    $filter = $crawler->fresh()->filter;

    expect($filter)->toBeInstanceOf(EisSearchFilter::class)
        ->and($filter->okpd2Codes)->toBe(['43', '43.29', 'F'])
        ->and($filter->hasScope())->toBeTrue();
});

test('коды ОКПД2 переживают round-trip через jsonb', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create([
        'filter' => new EisSearchFilter(stageCompleted: true, okpd2Codes: ['41.20', '43']),
    ]);

    $stored = json_decode(
        (string) DB::table('prc_crawlers')->where('id', $crawler->getKey())->value('filter'),
        associative: true,
    );

    expect($stored['okpd2Codes'])->toBe(['41.20', '43'])
        ->and($crawler->fresh()->filter->okpd2Codes)->toBe(['41.20', '43']);
});

test('профиль fz44 без фильтра вообще отвергается — путь filter = null через saving', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);

    expect(fn () => Crawler::factory()->for($registry)->create(['filter' => null]))
        ->toThrow(function (ValidationException $exception) {
            expect($exception->getMessage())->toContain('fz44')
                ->and($exception->getMessage())->toContain('ОКПД2');
        });
});

test('профиль fz44 с пустым списком кодов отвергается — путь validateAndCreate', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);

    expect(fn () => Crawler::factory()->for($registry)->create(
        ['filter' => ['stageCompleted' => true, 'okpd2Codes' => []]]
    ))->toThrow(ValidationException::class);
});

test('мусор вместо кода ОКПД2 отклоняется проверкой формы, а не доезжает до площадки', function () {
    $registry = Registry::factory()->create(['code' => 'fz44']);

    expect(fn () => Crawler::factory()->for($registry)->create(
        ['filter' => ['okpd2Codes' => ['строительство']]]
    ))->toThrow(ValidationException::class);
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
