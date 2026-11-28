<?php

declare(strict_types=1);

use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Parsing\ParsingRuleVersionStatus;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Pages\ViewParsingRuleSet;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\ParsingRuleSetResource;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\RelationManagers\VersionsRelationManager;
use App\Guards\Admin\Permissions\ParsingRuleSetPermission;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Procurement\Platform\Platform;
use App\Models\User;
use App\Repositories\Procurement\Parsing\ParsingRuleStoreRepository;
use App\Services\Procurement\Parsing\ParsingRuleManager;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function (): void {
    artisan('guard:sync-roles');
});

function parsingUiUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('роль оператора имеет права только на просмотр и создание наборов правил без активации, отката и автолечения', function (): void {
    $operator = parsingUiUser(OperatorRole::class);

    expect($operator->hasPermission(ParsingRuleSetPermission::ViewAny))->toBeTrue()
        ->and($operator->hasPermission(ParsingRuleSetPermission::View))->toBeTrue()
        ->and($operator->hasPermission(ParsingRuleSetPermission::Create))->toBeTrue()
        ->and($operator->hasPermission(ParsingRuleSetPermission::Update))->toBeFalse()
        ->and($operator->hasPermission(ParsingRuleSetPermission::Activate))->toBeFalse()
        ->and($operator->hasPermission(ParsingRuleSetPermission::Rollback))->toBeFalse()
        ->and($operator->hasPermission(ParsingRuleSetPermission::AutoHeal))->toBeFalse();
});

test('роль суперадмина обладает полным набором прав на управление правилами парсинга', function (): void {
    $superAdmin = parsingUiUser(SuperAdminRole::class);

    foreach (ParsingRuleSetPermission::cases() as $permission) {
        expect($superAdmin->hasPermission($permission))->toBeTrue();
    }
});

test('оператор и суперадмин открывают список, создание и просмотр ресурса правил парсинга, а аналитик получает 403', function (): void {
    $platform = Platform::factory()->create();
    $ruleSet = app(ParsingRuleStoreRepository::class)->ensureRuleSet($platform->id, StageCode::Listing);

    $analyst = parsingUiUser(AnalystRole::class);
    actingAs($analyst)->get(ParsingRuleSetResource::getUrl('index'))->assertForbidden();

    $operator = parsingUiUser(OperatorRole::class);
    actingAs($operator)->get(ParsingRuleSetResource::getUrl('index'))->assertOk();
    actingAs($operator)->get(ParsingRuleSetResource::getUrl('create'))->assertOk();
    actingAs($operator)->get(ParsingRuleSetResource::getUrl('view', ['record' => $ruleSet]))->assertOk();

    $superAdmin = parsingUiUser(SuperAdminRole::class);
    actingAs($superAdmin)->get(ParsingRuleSetResource::getUrl('index'))->assertOk();
    actingAs($superAdmin)->get(ParsingRuleSetResource::getUrl('create'))->assertOk();
    actingAs($superAdmin)->get(ParsingRuleSetResource::getUrl('view', ['record' => $ruleSet]))->assertOk();
});

test('ресурс правил парсинга имеет корректные метаданные навигации в группе Площадки', function (): void {
    expect(ParsingRuleSetResource::getNavigationGroup())->toBe('Площадки')
        ->and(ParsingRuleSetResource::getNavigationLabel())->toBe('Правила парсинга')
        ->and(ParsingRuleSetResource::getNavigationSort())->toBe(50);
});

test('Livewire действия управления версиями и режимом скрыты для оператора и выполняются суперадмином', function (): void {
    $platform = Platform::factory()->create();
    $manager = app(ParsingRuleManager::class);
    $validRules = [
        'format' => 'csv',
        'transport' => [
            'method' => 'GET',
            'page_param' => 'page',
            'page_size_param' => 'size',
            'default_page_size' => 50,
        ],
        'date_parsing' => [
            'formats' => ['d.m.Y'],
            'timezone' => 'Europe/Moscow',
        ],
        'csv_dialect' => [
            'delimiter' => ';',
            'enclosure' => '"',
            'escape' => '\\',
            'encoding' => 'UTF-8',
            'strip_bom' => true,
            'skip_empty_rows' => true,
            'header_row_index' => 0,
        ],
        'columns' => [
            'columns' => [
                [
                    'target_field' => 'external_id',
                    'header_aliases' => ['Номер закупки'],
                    'required' => true,
                ],
            ],
        ],
    ];

    $v1 = $manager->publish($platform->id, StageCode::Listing, $validRules, 'v1');
    $v2Draft = $manager->createDraft($platform->id, StageCode::Listing, $validRules, 'v2 draft');
    $v2Draft->update(['status' => ParsingRuleVersionStatus::Validated]);
    $ruleSet = $v1->ruleSet()->firstOrFail();

    $operator = parsingUiUser(OperatorRole::class);
    $superAdmin = parsingUiUser(SuperAdminRole::class);

    // Оператор не видит update_mode, но видит create_draft на ViewParsingRuleSet
    Livewire::actingAs($operator)
        ->test(ViewParsingRuleSet::class, ['record' => $ruleSet->getRouteKey()])
        ->assertActionHidden('update_mode')
        ->assertActionVisible('create_draft');

    // Оператор не видит activate и rollback в таблице версий
    Livewire::actingAs($operator)
        ->test(VersionsRelationManager::class, [
            'ownerRecord' => $ruleSet,
            'pageClass' => ViewParsingRuleSet::class,
        ])
        ->assertTableActionHidden('activate', $v2Draft)
        ->assertTableActionHidden('rollback', $v1);

    // Суперадмин может менять режим и создавать черновик
    Livewire::actingAs($superAdmin)
        ->test(ViewParsingRuleSet::class, ['record' => $ruleSet->getRouteKey()])
        ->assertActionVisible('update_mode')
        ->callAction('update_mode', data: [
            'auto_heal_mode' => AutoHealMode::AutoPromoteFallback->value,
            'is_enabled' => true,
            'reason' => 'Включён auto-heal через UI',
        ])
        ->assertHasNoActionErrors()
        ->callAction('create_draft', data: [
            'rules_json' => json_encode($validRules, JSON_THROW_ON_ERROR),
            'change_summary' => 'v3 via UI',
        ])
        ->assertHasNoActionErrors();

    expect($ruleSet->fresh()->auto_heal_mode)->toBe(AutoHealMode::AutoPromoteFallback);

    // Суперадмин может активировать черновик и откатиться к v1
    Livewire::actingAs($superAdmin)
        ->test(VersionsRelationManager::class, [
            'ownerRecord' => $ruleSet->fresh(),
            'pageClass' => ViewParsingRuleSet::class,
        ])
        ->assertTableActionVisible('activate', $v2Draft)
        ->callTableAction('activate', $v2Draft, data: ['reason' => 'Активация через UI'])
        ->assertHasNoTableActionErrors()
        ->callTableAction('rollback', $v1->fresh(), data: ['reason' => 'Откат к v1 через UI'])
        ->assertHasNoTableActionErrors();

    expect($v2Draft->fresh()->status)->toBe(ParsingRuleVersionStatus::Superseded)
        ->and($ruleSet->fresh()->current_version_number)->toBe(4);
});
