<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\Contracts\HasArchiveWindow;
use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Exceptions\Procurement\Crawler\PlatformAdapterUnavailableException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\CrawlerFilterFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('filter профиля читается типизированным объектом, а не мешком ключей', function () {
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
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
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
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
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);

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
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);

    expect(fn () => Crawler::factory()->for($registry)->create(
        ['filter' => ['priceTo' => 0, 'okpd2Codes' => ['43']]]
    ))->toThrow(ValidationException::class);
});

test('реестр без typed filter не сохраняет профиль даже с пустым filter', function () {
    $registry = Registry::factory()->eis()->create(['code' => 'fz223']);

    expect(fn () => Crawler::factory()->for($registry)->create(['filter' => null]))
        ->toThrow(PlatformAdapterUnavailableException::class, 'fz223');
});

test('коды ОКПД2 доезжают до объекта фильтра списком строк', function () {
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create([
        'filter' => ['okpd2Codes' => ['43', '43.29', 'F']],
    ]);

    $filter = $crawler->fresh()->filter;

    expect($filter)->toBeInstanceOf(EisSearchFilter::class)
        ->and($filter->okpd2Codes)->toBe(['43', '43.29', 'F'])
        ->and($filter->hasScope())->toBeTrue();
});

test('коды ОКПД2 переживают round-trip через jsonb', function () {
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
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
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);

    expect(fn () => Crawler::factory()->for($registry)->create(['filter' => null]))
        ->toThrow(function (ValidationException $exception) {
            expect($exception->getMessage())->toContain('fz44')
                ->and($exception->getMessage())->toContain('ОКПД2');
        });
});

test('профиль fz44 с пустым списком кодов отвергается — путь validateAndCreate', function () {
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);

    expect(fn () => Crawler::factory()->for($registry)->create(
        ['filter' => ['stageCompleted' => true, 'okpd2Codes' => []]]
    ))->toThrow(ValidationException::class);
});

test('мусор вместо кода ОКПД2 отклоняется проверкой формы, а не доезжает до площадки', function () {
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);

    expect(fn () => Crawler::factory()->for($registry)->create(
        ['filter' => ['okpd2Codes' => ['строительство']]]
    ))->toThrow(ValidationException::class);
});

test('поля сортировки переживают round-trip через jsonb', function () {
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create([
        'filter' => new EisSearchFilter(okpd2Codes: ['43'], sortField: ListingSortField::Published, sortAscending: true),
    ]);

    $stored = json_decode(
        (string) DB::table('prc_crawlers')->where('id', $crawler->getKey())->value('filter'),
        associative: true,
    );

    expect($stored['sortField'])->toBe('published')
        ->and($stored['sortAscending'])->toBeTrue();

    $filter = $crawler->fresh()->filter;

    expect($filter)->toBeInstanceOf(EisSearchFilter::class)
        ->and($filter->sortField)->toBe(ListingSortField::Published)
        ->and($filter->sortAscending)->toBeTrue();
});

test('класс фильтра резолвится по exact platform and registry capability', function () {
    $platform = new Platform;
    $platform->code = 'eis';
    $registry = new Registry;
    $registry->code = 'fz44';
    $registry->setRelation('platform', $platform);

    expect(app(CrawlerFilterFactory::class)->for(registry: $registry))->toBe(EisSearchFilter::class);
});

test('реестр без записи в карте фильтров даёт исключение с кодом реестра', function () {
    $platform = new Platform;
    $platform->code = 'eis';
    $registry = new Registry;
    $registry->code = 'fz223';
    $registry->setRelation('platform', $platform);

    expect(fn () => app(CrawlerFilterFactory::class)->for(registry: $registry))
        ->toThrow(RuntimeException::class, 'fz223');
});

test('EisSearchFilter отдаёт окно archive-прогона через контракт HasArchiveWindow (P3.8, D113)', function () {
    $filter = new EisSearchFilter(
        okpd2Codes: ['43'],
        publishedFrom: CarbonImmutable::parse('2025-01-01'),
        publishedTo: CarbonImmutable::parse('2025-12-31'),
    );

    expect($filter)->toBeInstanceOf(HasArchiveWindow::class)
        ->and($filter->archiveWindowFrom()?->toDateString())->toBe('2025-01-01')
        ->and($filter->archiveWindowTo()?->toDateString())->toBe('2025-12-31');
});

test('без заполненных дат archiveWindowFrom/To отдают null', function () {
    $filter = new EisSearchFilter(okpd2Codes: ['43']);

    expect($filter->archiveWindowFrom())->toBeNull()
        ->and($filter->archiveWindowTo())->toBeNull();
});

test('publishedTo раньше publishedFrom отклоняется валидацией на сохранении профиля (P3.8, D113)', function () {
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);

    expect(fn () => Crawler::factory()->for($registry)->create([
        'filter' => ['okpd2Codes' => ['43'], 'publishedFrom' => '2025-06-01', 'publishedTo' => '2025-01-01'],
    ]))->toThrow(ValidationException::class);
});
