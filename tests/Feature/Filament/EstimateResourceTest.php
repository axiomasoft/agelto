<?php

declare(strict_types=1);

use App\Enums\Procurement\Estimate\RowQualityReason;
use App\Filament\Resources\Procurement\Estimates\EstimateResource;
use App\Filament\Resources\Procurement\Estimates\Pages\ListEstimates;
use App\Filament\Resources\Procurement\Estimates\Pages\ViewEstimate;
use App\Filament\Resources\Procurement\Estimates\RelationManagers\ItemsRelationManager;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

// PHP-роли зеркалятся в таблицу roles до назначения (class_name → assignRole).
beforeEach(fn () => artisan('guard:sync-roles'));

function estimateResourceOperator(): User
{
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    return $operator;
}

test('список смет открывается и показывает счётчик помеченных позиций', function (): void {
    actingAs(estimateResourceOperator());

    $estimate = Estimate::factory()->create();
    Item::factory()->count(2)->for($estimate)->create();
    Item::factory()->for($estimate)->create([
        'quality' => ['model' => ['reason' => RowQualityReason::Unreadable->value, 'fields' => ['quantity']]],
    ]);

    Livewire::test(ListEstimates::class)
        ->assertSuccessful()
        ->assertTableColumnStateSet('marked_items_count', '1 из 3', record: $estimate);
});

test('просмотр сметы доступен оператору по прямой ссылке', function (): void {
    actingAs(estimateResourceOperator());

    $estimate = Estimate::factory()->create();

    Livewire::test(ViewEstimate::class, ['record' => $estimate->getKey()])
        ->assertSuccessful();
});

test('фильтр «только помеченные» отбирает ожидаемое множество позиций', function (): void {
    actingAs(estimateResourceOperator());

    $estimate = Estimate::factory()->create();
    $clean = Item::factory()->for($estimate)->create(['quality' => null]);
    $marked = Item::factory()->for($estimate)->create([
        'quality' => ['validator' => ['reasons' => [RowQualityReason::NumberingGap->value], 'notes' => ['разрыв нумерации']]],
    ]);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $estimate,
        'pageClass' => ViewEstimate::class,
    ])
        ->filterTable('marked')
        ->assertCanSeeTableRecords([$marked])
        ->assertCanNotSeeTableRecords([$clean]);
});

test('число запросов списка не растёт с числом позиций сметы', function (): void {
    actingAs(estimateResourceOperator());

    $estimate = Estimate::factory()->create();
    Item::factory()->count(3)->for($estimate)->create();

    // Прогрев процессных кэшей (права AzGuard и т.п.) ДО замера — иначе первый рендер платит
    // за холодный кэш, и сравнение считает это ростом от числа позиций.
    Livewire::test(ListEstimates::class)->assertSuccessful();

    DB::enableQueryLog();
    Livewire::test(ListEstimates::class)->assertSuccessful();
    $fewItemsQueryCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    Item::factory()->count(200)->for($estimate)->create();
    DB::flushQueryLog();

    Livewire::test(ListEstimates::class)->assertSuccessful();
    $manyItemsQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($manyItemsQueryCount)->toBe($fewItemsQueryCount);
});

test('без права на index — 403', function (): void {
    $guest = User::factory()->create();
    actingAs($guest);

    expect(EstimateResource::canViewAny())->toBeFalse();

    get(EstimateResource::getUrl('index'))->assertForbidden();
});
