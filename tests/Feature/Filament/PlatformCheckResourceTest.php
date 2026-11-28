<?php

declare(strict_types=1);

use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Filament\Resources\Procurement\Health\PlatformChecks\Pages\ListPlatformChecks;
use App\Filament\Resources\Procurement\Health\PlatformChecks\Pages\ViewPlatformCheck;
use App\Filament\Resources\Procurement\Health\PlatformChecks\PlatformCheckResource;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Health\PlatformCheck;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(fn () => artisan('guard:sync-roles'));

function platformCheckOperator(): User
{
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    return $operator;
}

test('оператор открывает список проверок площадок', function (): void {
    actingAs(platformCheckOperator());

    $check = PlatformCheck::factory()->create();

    Livewire::test(ListPlatformChecks::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$check]);
});

test('оператор фильтрует проверки по вердикту', function (): void {
    actingAs(platformCheckOperator());

    $broken = PlatformCheck::factory()->create(['verdict' => PlatformCheckVerdict::Broken]);
    $ok = PlatformCheck::factory()->create(['verdict' => PlatformCheckVerdict::Ok]);

    Livewire::test(ListPlatformChecks::class)
        ->filterTable('verdict', PlatformCheckVerdict::Broken->value)
        ->assertCanSeeTableRecords([$broken])
        ->assertCanNotSeeTableRecords([$ok]);
});

test('оператор открывает просмотр проверки площадки', function (): void {
    actingAs(platformCheckOperator());

    $check = PlatformCheck::factory()->create(['measured' => ['missing_anchors' => 1]]);

    Livewire::test(ViewPlatformCheck::class, ['record' => $check->getKey()])
        ->assertSuccessful()
        ->assertSee('missing_anchors');
});

test('ресурс проверок не регистрирует маршруты создания и редактирования', function (): void {
    expect(PlatformCheckResource::getPages())->toHaveKeys(['index', 'view']);
    expect(Route::has('filament.admin.resources.procurement.health.platform-checks.create'))->toBeFalse();
    expect(Route::has('filament.admin.resources.procurement.health.platform-checks.edit'))->toBeFalse();
});
