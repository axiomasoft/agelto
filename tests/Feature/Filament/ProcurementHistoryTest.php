<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementEventType;
use App\Filament\Resources\Procurement\Procurements\Pages\ViewProcurement;
use App\Filament\Resources\Procurement\Procurements\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Procurement\Procurements\RelationManagers\RevisionsRelationManager;
use App\Guards\Admin\Roles\AnalystRole;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementRevision;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function (): void {
    artisan('guard:sync-roles');

    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    actingAs($analyst);
});

test('вкладка событий показывает записи закупки в окне по умолчанию', function (): void {
    $procurement = Procurement::factory()->create(['published_at' => now()->subDays(10)]);
    $recent = ProcurementEvent::factory()->for($procurement)->create([
        'event_type' => ProcurementEventType::StatusChanged,
        'field' => 'status',
        'old_value' => 'bidding',
        'new_value' => 'evaluation',
        'occurred_at' => now()->subDay(),
    ]);
    $old = ProcurementEvent::factory()->for($procurement)->create(['occurred_at' => now()->subDays(91)]);

    Livewire::test(EventsRelationManager::class, [
        'ownerRecord' => $procurement,
        'pageClass' => ViewProcurement::class,
    ])
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$old]);
});

test('вкладка редакций показывает записи закупки', function (): void {
    $procurement = Procurement::factory()->create(['published_at' => now()->subDays(10)]);
    $revision = ProcurementRevision::factory()->for($procurement)->create([
        'revision_number' => 2,
        'detected_at' => now()->subDay(),
    ]);

    Livewire::test(RevisionsRelationManager::class, [
        'ownerRecord' => $procurement,
        'pageClass' => ViewProcurement::class,
    ])->assertCanSeeTableRecords([$revision]);
});

test('сравнение с предыдущей редакцией показывает изменённые поля', function (): void {
    $procurement = Procurement::factory()->create(['published_at' => now()->subDays(10)]);
    ProcurementRevision::factory()->for($procurement)->create([
        'revision_number' => 1,
        'snapshot' => ['title' => 'Было', 'currency' => 'RUB'],
        'detected_at' => now()->subDays(2),
    ]);
    $revision = ProcurementRevision::factory()->for($procurement)->create([
        'revision_number' => 2,
        'snapshot' => ['title' => 'Стало', 'currency' => 'RUB'],
        'detected_at' => now()->subDay(),
    ]);

    $component = Livewire::test(RevisionsRelationManager::class, [
        'ownerRecord' => $procurement,
        'pageClass' => ViewProcurement::class,
    ])->mountTableAction('comparePrevious', record: $revision);

    $content = $component->instance()->getMountedAction()?->getModalContent();

    expect((string) $content)
        ->toContain('Наименование')
        ->toContain('Было')
        ->toContain('Стало')
        ->not->toContain('Валюта');
});

test('карточка закупки без истории рендерится без ошибки', function (): void {
    $procurement = Procurement::factory()->create();

    Livewire::test(ViewProcurement::class, ['record' => $procurement->getKey()])
        ->assertSuccessful()
        ->assertSee('Длительности')
        ->assertSee('идёт');
});

test('витрина истории не содержит действий мутации', function (): void {
    $relationManagers = [
        app_path('Filament/Resources/Procurement/Procurements/RelationManagers/EventsRelationManager.php'),
        app_path('Filament/Resources/Procurement/Procurements/RelationManagers/RevisionsRelationManager.php'),
    ];

    foreach ($relationManagers as $relationManager) {
        expect(file_get_contents($relationManager))
            ->not->toContain('CreateAction')
            ->not->toContain('EditAction')
            ->not->toContain('DeleteAction')
            ->not->toContain('DeleteBulkAction')
            ->not->toContain('->form(');
    }
});
