<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\CreateCrawler;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\EditCrawler;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Registry;
use App\Models\User;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

test('сохранение профиля формой даёт типизированный фильтр и acquisition kind', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'ЕИС 44-ФЗ — архив 2025',
            'listing_kind' => AcquisitionKind::Archive->value,
            'filter' => ['stageCompleted' => true, 'okpd2Codes' => ['43']],
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

    expect($crawler->listing_kind)->toBe(AcquisitionKind::Archive);
    expect(Schema::hasColumn('prc_crawlers', 'options'))->toBeFalse();
});

test('execution lanes and source fallback are owned by Process and cannot be changed by a profile', function (): void {
    actingAs(crawlerFormOperator());
    Livewire::test(CreateCrawler::class)->assertFormFieldDoesNotExist('execution_lane')
        ->assertFormFieldDoesNotExist('source_method')->assertFormFieldDoesNotExist('fallback_method');
    foreach (['execution_lane', 'source_method', 'fallback_method'] as $column) {
        expect(Schema::hasColumn('prc_crawlers', $column))->toBeFalse();
    }
});

test('mandatory CSV columns and request retry policy cannot be disabled by the profile form', function (): void {
    actingAs(crawlerFormOperator());
    $crawler = EisCrawlerFactory::new()->create();
    Livewire::test(EditCrawler::class, ['record' => $crawler->id])
        ->assertFormFieldDoesNotExist('options.columns')->assertFormFieldDoesNotExist('options.retries')
        ->assertFormFieldDoesNotExist('options.retryDelayMs');
});

test('невалидное cron-выражение отклоняется на форме', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'ЕИС 44-ФЗ — свежий поток',
            'listing_kind' => AcquisitionKind::Fresh->value,
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
            'listing_kind' => AcquisitionKind::Archive->value,
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

test('the profile UI uses the registered extension and does not offer a legacy CSV URL preview', function (): void {
    actingAs(crawlerFormOperator());
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43', 'external_id' => '10501000']);
    Livewire::test(CreateCrawler::class)->fillForm(['registry_id' => $registry->id])
        ->assertFormFieldExists('filter.okpd2Codes')->assertFormFieldDoesNotExist('url_preview');
    // The emitted OKPD2 query is asserted in T17ProfilePreparationTest and the platform contract kit.
});

test('форма сохраняет профиль с полями сортировки', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'ЕИС 44-ФЗ — актуальный поток',
            'listing_kind' => AcquisitionKind::Fresh->value,
            'filter' => ['okpd2Codes' => ['43'], 'sortField' => ListingSortField::Updated->value, 'sortAscending' => false],
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

test('fresh and archive profiles cannot reintroduce the legacy stop policy', function (AcquisitionKind $kind): void {
    actingAs(crawlerFormOperator());
    $crawler = EisCrawlerFactory::new()->create(['listing_kind' => $kind]);
    Livewire::test(EditCrawler::class, ['record' => $crawler->id])->assertFormFieldDoesNotExist('stop_policy');
    expect(Schema::hasColumn('prc_crawlers', 'stop_policy'))->toBeFalse();
})->with([AcquisitionKind::Fresh, AcquisitionKind::Archive]);

test('форма отклоняет профиль fz44 без выбранного узла ОКПД2', function (): void {
    actingAs(crawlerFormOperator());

    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43']);

    Livewire::test(CreateCrawler::class)
        ->fillForm([
            'registry_id' => $registry->getKey(),
            'name' => 'Профиль без скоупа',
            'listing_kind' => AcquisitionKind::Archive->value,
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
    $crawler = EisCrawlerFactory::new()->for($registry)->withProxies($proxy)->create();
    $profileId = $crawler->route_profile_id;
    Livewire::test(EditCrawler::class, ['record' => $crawler->getKey()])
        ->fillForm(['route_profile_id' => null])->call('save')->assertHasNoFormErrors();
    expect($crawler->refresh()->route_profile_id)->toBeNull()
        ->and(DB::table('prc_network_route_profile_members')->where('profile_id', $profileId)->pluck('route_id')->all())->toBe([$proxy->route_id]);
    Livewire::test(EditCrawler::class, ['record' => $crawler->getKey()])
        ->fillForm(['route_profile_id' => $profileId])->call('save')->assertHasNoFormErrors();
    expect($crawler->refresh()->route_profile_id)->toBe($profileId);
});

test('форма показывает привязки и страну только для профильного режима', function (): void {
    actingAs(crawlerFormOperator());
    // Canonical profile selector replaces per-crawler geo and many-to-many node controls.
    Livewire::test(CreateCrawler::class)->assertFormFieldExists('route_profile_id')
        ->assertFormFieldDoesNotExist('proxies')->assertFormFieldDoesNotExist('proxy_country')->assertFormFieldDoesNotExist('proxy_mode');
});
