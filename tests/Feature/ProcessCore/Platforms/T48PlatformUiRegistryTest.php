<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Procurements\Pages\ListProcurements;
use App\Filament\Resources\Procurement\Procurements\Pages\ViewProcurement;
use App\Guards\Admin\Roles\AnalystRole;
use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;

pest()->group('t48', 'p33');

beforeEach(function (): void {
    test()->artisan('guard:sync-roles')->assertSuccessful();
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    test()->actingAs($analyst);
    config()->set('platforms.definitions.json', JsonProcurementDefinition::class);
});

test('T48 JSON без extension рендерит настоящую общую карточку без EIS queries', function (): void {
    $registry = Registry::factory()->for(Platform::factory()->create(['code' => 'json']))->create();
    $procurement = Procurement::factory()->for($registry)->create(['title' => 'JSON common procurement']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        Livewire::test(ViewProcurement::class, ['record' => $procurement->id])
            ->assertSuccessful()->assertSee('JSON common procurement')->assertSee('Наблюдаемая длительность')
            ->assertDontSee('ИКЗ')->assertDontSee('Номер ЭТП');
        expect(collect(DB::getQueryLog())->contains(fn ($query): bool => str_contains($query['query'], 'prc_eis_details')))->toBeFalse();
    } finally {
        DB::disableQueryLog();
    }
});

test('T48 EIS extension читает свои поля только в detail schema и экранирует данные', function (): void {
    $procurement = Procurement::factory()->create();
    EisDetails::factory()->create([
        'procurement_id' => $procurement->id, 'ikz' => '00042',
        'etp_number' => 'ETP-42', 'registry_guid' => '<script>fixture</script>',
    ]);
    Livewire::test(ViewProcurement::class, ['record' => $procurement->id])
        ->assertSuccessful()->assertSee('ИКЗ')->assertSee('00042')->assertSee('ETP-42')
        ->assertSee('<script>fixture</script>')->assertDontSee('<script>fixture</script>', escape: false);
});

test('T48 настоящий список закупок не получает N+1 при росте строк и не загружает unused extensions', function (): void {
    Procurement::factory()->create();
    Livewire::test(ListProcurements::class)->assertSuccessful();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        Livewire::test(ListProcurements::class)->set('tableRecordsPerPage', 25)->assertCountTableRecords(1);
        $few = count(DB::getQueryLog());
        Procurement::factory()->count(19)->create();
        DB::flushQueryLog();
        Livewire::test(ListProcurements::class)->set('tableRecordsPerPage', 25)->assertCountTableRecords(20);
        expect(count(DB::getQueryLog()))->toBe($few)
            ->and(collect(DB::getQueryLog())->contains(fn ($query): bool => str_contains($query['query'], 'prc_eis_details')))->toBeFalse();
    } finally {
        DB::disableQueryLog();
    }
});
