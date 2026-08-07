<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\CreateCrawler;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\EditCrawler;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Registry;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

// PHP-роли зеркалятся в таблицу roles до назначения (class_name → assignRole).
beforeEach(fn () => artisan('guard:sync-roles'));

function crawlerFormOperator(): User
{
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    return $operator;
}

test('сохранение профиля формой даёт типизированный фильтр и обязательные колонки', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'ЕИС 44-ФЗ — архив 2025',
            'mode' => CrawlerMode::Archive->value,
            'source_method' => SourceMethod::Csv->value,
            'filter' => ['stageCompleted' => true, 'okpd2Codes' => ['43']],
            'options' => ['columns' => ['nameOrderCsv']],
            'schedule' => null,
            'is_active' => true,
            'use_proxy' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $crawler = Crawler::query()->sole();
    $filter = $crawler->filter;

    expect($filter)->toBeInstanceOf(EisSearchFilter::class);
    /** @var EisSearchFilter $filter */
    expect($filter->stageCompleted)->toBeTrue();
    expect($filter->okpd2Codes)->toBe(['43']);

    $columns = $crawler->options?->toArray()['columns'] ?? [];

    expect($columns)
        ->toHaveKey('registryNumberCsv', true)
        ->toHaveKey('publishDateCsv', true)
        ->toHaveKey('lastDateChangeCsv', true)
        ->toHaveKey('nameOrderCsv', true);
});

test('обязательные колонки нельзя снять даже при попытке отправить фильтр без них', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43']);
    $crawler = Crawler::factory()->for($registry)->create([
        'filter' => new EisSearchFilter(okpd2Codes: ['43']),
        'options' => ['columns' => ['registryNumberCsv' => true, 'publishDateCsv' => true, 'lastDateChangeCsv' => true]],
    ]);

    Livewire::test(EditCrawler::class, ['record' => $crawler->getKey()])
        ->fillForm(['options' => ['columns' => []]])
        ->call('save')
        ->assertHasNoFormErrors();

    $columns = $crawler->refresh()->options?->toArray()['columns'] ?? [];

    expect($columns)
        ->toHaveKey('registryNumberCsv', true)
        ->toHaveKey('publishDateCsv', true)
        ->toHaveKey('lastDateChangeCsv', true);
});

test('невалидное cron-выражение отклоняется на форме', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->create(['code' => 'fz44']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'ЕИС 44-ФЗ — свежий поток',
            'mode' => CrawlerMode::Fresh->value,
            'source_method' => SourceMethod::Csv->value,
            'schedule' => 'не cron',
            'is_active' => false,
            'use_proxy' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['schedule']);
});

test('неизвестный ключ фильтра отклоняется формой так же, как моделью', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->create(['code' => 'fz44']);

    // Гейт неизвестного ключа стоит в модельном `saving` (P4.3), форма его не дублирует —
    // Filament ловит исключение сохранения и не создаёт запись, вместо проброса выше.
    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'Профиль с опечаткой в фильтре',
            'mode' => CrawlerMode::Archive->value,
            'source_method' => SourceMethod::Csv->value,
            // Заведомо чужой ключ: `okpd2Codes` теперь валиден, а `okpd2` отличается от него одним
            // суффиксом — тест на такой строке был бы хрупким (research/03-p2-okpd2-design.md §2.4).
            'filter' => ['stageUnknown' => true, 'okpd2Codes' => ['43']],
            'is_active' => true,
            'use_proxy' => true,
        ])
        ->call('create');

    expect(Crawler::query()->count())->toBe(0);
});

test('форма отклоняет профиль fz44 без выбранного узла ОКПД2', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->create(['code' => 'fz44']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'Профиль без скоупа',
            'mode' => CrawlerMode::Archive->value,
            'source_method' => SourceMethod::Csv->value,
            'filter' => ['stageCompleted' => true],
            'is_active' => true,
            'use_proxy' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['filter.okpd2Codes']);

    expect(Crawler::query()->count())->toBe(0);
});
