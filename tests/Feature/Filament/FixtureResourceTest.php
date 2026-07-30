<?php

declare(strict_types=1);

use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\FixtureClass;
use App\Enums\Procurement\GoldSource;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\CreateFixture;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ListFixtures;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixture;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Ai\Fixture;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

// PHP-роли зеркалятся в таблицу roles до назначения (class_name → assignRole).
beforeEach(fn () => artisan('guard:sync-roles'));

test('форма создаёт запись со всеми обязательными полями — title и обе оси', function (): void {
    Storage::fake('local');
    Storage::fake('estimates');

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    Livewire::test(CreateFixture::class)
        ->fillForm([
            'source' => UploadedFile::fake()->create('source.xlsx', 100),
            'title' => 'Эталон КС-2 №1',
            'document_type' => AiDocumentType::Estimate->value,
            'owner_document_type' => AiDocumentType::Estimate->value,
            'type_profile' => [
                'class' => FixtureClass::Estimate->value,
                'form' => 'KS2',
                'gold_source' => GoldSource::Deterministic->value,
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $fixture = Fixture::query()->sole();

    expect($fixture->title)->toBe('Эталон КС-2 №1')
        ->and($fixture->document_type)->toBe(AiDocumentType::Estimate)
        ->and($fixture->owner_document_type)->toBe(AiDocumentType::Estimate);
});

test('форма отказывает без обязательного title', function (): void {
    Storage::fake('local');
    Storage::fake('estimates');

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    Livewire::test(CreateFixture::class)
        ->fillForm([
            'source' => UploadedFile::fake()->create('source.xlsx', 100),
            'title' => '',
            'document_type' => AiDocumentType::Estimate->value,
            'owner_document_type' => AiDocumentType::Estimate->value,
            'type_profile' => [
                'class' => FixtureClass::Estimate->value,
                'form' => 'KS2',
                'gold_source' => GoldSource::Deterministic->value,
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['title']);

    expect(Fixture::query()->count())->toBe(0);
});

test('фильтр реестра по полю профиля class сужает список', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    $est = Fixture::factory()->est()->create();
    $neg = Fixture::factory()->neg()->create();

    Livewire::test(ListFixtures::class)
        ->filterTable('profile_field', ['key' => 'class', 'value' => FixtureClass::Estimate->value])
        ->assertCanSeeTableRecords([$est])
        ->assertCanNotSeeTableRecords([$neg]);
});

test('бейдж манифеста честно показывает расхождение реестра с git-SSOT', function (): void {
    $manifestPath = tempnam(sys_get_temp_dir(), 'fixture-manifest').'.json';
    $fixtureInManifest = Fixture::factory()->est()->create();
    $fixtureNotInManifest = Fixture::factory()->est()->create();

    file_put_contents($manifestPath, json_encode([
        'version' => 1,
        'fixtures' => [
            ['code' => $fixtureInManifest->code],
        ],
    ], JSON_THROW_ON_ERROR));

    config(['ai.fixtures.manifest_path' => $manifestPath]);

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    Livewire::test(ListFixtures::class)
        ->assertTableColumnStateSet('in_manifest', 'в манифесте', record: $fixtureInManifest)
        ->assertTableColumnStateSet('in_manifest', 'нет в манифесте', record: $fixtureNotInManifest);

    unlink($manifestPath);
});

test('без права на index и create — 403', function (): void {
    $guest = User::factory()->create();
    actingAs($guest);

    expect(FixtureResource::canViewAny())->toBeFalse()
        ->and(FixtureResource::canCreate())->toBeFalse();

    get(FixtureResource::getUrl('index'))->assertForbidden();
    get(FixtureResource::getUrl('create'))->assertForbidden();
});

test('аналитик видит реестр, но не может создавать эталоны', function (): void {
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    actingAs($analyst);

    expect(FixtureResource::canViewAny())->toBeTrue()
        ->and(FixtureResource::canCreate())->toBeFalse();
});

test('карточка эталона доступна оператору по прямой ссылке', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    $fixture = Fixture::factory()->est()->create();

    Livewire::test(ViewFixture::class, ['record' => $fixture->getKey()])
        ->assertSuccessful();
});
