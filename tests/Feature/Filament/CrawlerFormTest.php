<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Crawler\RunStopPolicy;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\CreateCrawler;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\EditCrawler;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Registry;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
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

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'ЕИС 44-ФЗ — архив 2025',
            'mode' => CrawlerMode::Archive->value,
            'stop_policy' => RunStopPolicy::Default->value,
            'source_method' => SourceMethod::Csv->value,
            'filter' => ['stageCompleted' => true, 'okpd2Codes' => ['43']],
            'options' => ['columns' => ['nameOrderCsv']],
            'schedule' => null,
            'is_active' => true,
            'route_profile_id' => null,
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

test('форма сохраняет long execution lane как профильный enum', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'Архив с длинным контуром',
            'mode' => CrawlerMode::Archive->value,
            'execution_lane' => CrawlerExecutionLane::Long->value,
            'stop_policy' => RunStopPolicy::Default->value,
            'source_method' => SourceMethod::Csv->value,
            'filter' => ['stageCompleted' => true, 'okpd2Codes' => ['43']],
            'options' => ['columns' => []],
            'is_active' => true,
            'route_profile_id' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Crawler::query()->sole()->execution_lane)->toBe(CrawlerExecutionLane::Long);
});

test('обязательные колонки нельзя снять даже при попытке отправить фильтр без них', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
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

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'ЕИС 44-ФЗ — свежий поток',
            'mode' => CrawlerMode::Fresh->value,
            'stop_policy' => RunStopPolicy::Default->value,
            'source_method' => SourceMethod::Csv->value,
            'schedule' => 'не cron',
            'is_active' => false,
            'route_profile_id' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['schedule']);
});

test('неизвестный ключ фильтра отклоняется формой так же, как моделью', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);

    // Гейт неизвестного ключа стоит в модельном `saving` (P4.3), форма его не дублирует —
    // Filament ловит исключение сохранения и не создаёт запись, вместо проброса выше.
    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'Профиль с опечаткой в фильтре',
            'mode' => CrawlerMode::Archive->value,
            'stop_policy' => RunStopPolicy::Default->value,
            'source_method' => SourceMethod::Csv->value,
            // Заведомо чужой ключ: `okpd2Codes` теперь валиден, а `okpd2` отличается от него одним
            // суффиксом — тест на такой строке был бы хрупким (research/03-p2-okpd2-design.md §2.4).
            'filter' => ['stageUnknown' => true, 'okpd2Codes' => ['43']],
            'is_active' => true,
            'route_profile_id' => null,
        ])
        ->call('create');

    expect(Crawler::query()->count())->toBe(0);
});

test('опции ОКПД2 сгруппированы по родительскому узлу и находятся поиском по коду и по названию', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $root = Okpd2Node::factory()->create([
        'platform_id' => $registry->platform_id,
        'code' => '43',
        'name' => 'Работы строительные специализированные',
        'parent_code' => null,
    ]);
    Okpd2Node::factory()->create([
        'platform_id' => $registry->platform_id,
        'code' => '43.99',
        'name' => 'Работы строительные специализированные прочие',
        'parent_code' => $root->code,
    ]);

    Livewire::test(CreateCrawler::class)
        ->fillForm(['registry_id' => $registry->getKey()])
        ->assertFormFieldExists('filter.okpd2Codes', function (Select $field): bool {
            expect($field->isSearchable())->toBeTrue();

            $options = $field->getOptions();

            expect($options)->toHaveKey('43 — Работы строительные специализированные');
            expect($options['43 — Работы строительные специализированные'])->toHaveKey('43.99');

            return true;
        });
});

test('превью URL несёт okpd2Ids и okpd2IdsWithNested после выбора узла ОКПД2', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create([
        'platform_id' => $registry->platform_id,
        'code' => '43',
        'external_id' => '10501000',
    ]);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'mode' => CrawlerMode::Archive->value,
            'stop_policy' => RunStopPolicy::Default->value,
            'source_method' => SourceMethod::Csv->value,
            'filter' => ['okpd2Codes' => ['43']],
        ])
        ->assertFormComponentExists('url_preview', function (Placeholder $component): bool {
            $preview = (string) $component->getContent();

            expect($preview)
                ->toContain('okpd2Ids=10501000')
                ->toContain('okpd2IdsWithNested=on');

            return true;
        });
});

test('форма сохраняет профиль с полями сортировки', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'ЕИС 44-ФЗ — актуальный поток',
            'mode' => CrawlerMode::Fresh->value,
            'stop_policy' => RunStopPolicy::Default->value,
            'source_method' => SourceMethod::Csv->value,
            'filter' => ['okpd2Codes' => ['43'], 'sortField' => ListingSortField::Updated->value, 'sortAscending' => false],
            'options' => ['columns' => []],
            'is_active' => true,
            'route_profile_id' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $filter = Crawler::query()->sole()->filter;

    expect($filter)->toBeInstanceOf(EisSearchFilter::class);
    /** @var EisSearchFilter $filter */
    expect($filter->sortField)->toBe(ListingSortField::Updated);
    expect($filter->sortAscending)->toBeFalse();
});

test('форма сохраняет профиль с политикой остановки «только актуальные данные»', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'ЕИС 44-ФЗ — только актуальные',
            'mode' => CrawlerMode::Fresh->value,
            'stop_policy' => RunStopPolicy::OnlyFresh->value,
            'source_method' => SourceMethod::Csv->value,
            'filter' => ['okpd2Codes' => ['43']],
            'options' => ['columns' => []],
            'is_active' => true,
            'route_profile_id' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Crawler::query()->sole()->stop_policy)->toBe(RunStopPolicy::OnlyFresh);
});

test('форма не сохраняет политику «только актуальные данные» на archive-профиле — та же ошибка, что у модели', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43']);

    // Гейт (P3.3) стоит в модельном `saving()`, форма его не дублирует — Filament ловит
    // исключение сохранения и не создаёт запись (тот же приём, что неизвестный ключ фильтра).
    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'Архив с ошибочной политикой',
            'mode' => CrawlerMode::Archive->value,
            'stop_policy' => RunStopPolicy::OnlyFresh->value,
            'source_method' => SourceMethod::Csv->value,
            'filter' => ['okpd2Codes' => ['43']],
            'is_active' => true,
            'route_profile_id' => null,
        ])
        ->call('create');

    expect(Crawler::query()->count())->toBe(0);
});

test('форма отклоняет профиль fz44 без выбранного узла ОКПД2', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'Профиль без скоупа',
            'mode' => CrawlerMode::Archive->value,
            'stop_policy' => RunStopPolicy::Default->value,
            'source_method' => SourceMethod::Csv->value,
            'filter' => ['stageCompleted' => true],
            'is_active' => true,
            'route_profile_id' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['filter.okpd2Codes']);

    expect(Crawler::query()->count())->toBe(0);
});

test('форма сначала сохраняет direct-профиль с привязкой, затем разрешает профильный режим', function (): void {
    actingAs(crawlerFormOperator());
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43']);
    $proxy = Proxy::factory()->create();
    $crawler = Crawler::factory()->for($registry)->withProxies($proxy)->create(['options' => null]);
    $profileId = $crawler->route_profile_id;
    Livewire::test(EditCrawler::class, ['record' => $crawler->getKey()])
        ->fillForm(['route_profile_id' => null, 'options' => ['columns' => []]])->call('save')->assertHasNoFormErrors();
    expect($crawler->refresh()->route_profile_id)->toBeNull()
        ->and(DB::table('prc_network_route_profile_members')->where('profile_id', $profileId)->pluck('route_id')->all())->toBe([$proxy->route_id]);
    Livewire::test(EditCrawler::class, ['record' => $crawler->getKey()])
        ->fillForm(['route_profile_id' => $profileId, 'options' => ['columns' => []]])->call('save')->assertHasNoFormErrors();
    expect($crawler->refresh()->route_profile_id)->toBe($profileId);
});

test('форма показывает привязки и страну только для профильного режима', function (): void {
    actingAs(crawlerFormOperator());
    // Canonical profile selector replaces per-crawler geo and many-to-many node controls.
    Livewire::test(CreateCrawler::class)->assertFormFieldExists('route_profile_id')
        ->assertFormFieldDoesNotExist('proxies')->assertFormFieldDoesNotExist('proxy_country')->assertFormFieldDoesNotExist('proxy_mode');
});
