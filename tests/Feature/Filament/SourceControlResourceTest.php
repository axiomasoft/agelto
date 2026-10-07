<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceIncidentType;
use App\Filament\Resources\Procurement\Crawler\SourceCircuits\Pages\EditSourceCircuit;
use App\Filament\Resources\Procurement\Crawler\SourceCircuits\Pages\ListSourceCircuits;
use App\Filament\Resources\Procurement\Crawler\SourceIncidents\Pages\ListSourceIncidents;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function (): void {
    artisan('guard:sync-roles')->assertExitCode(0);
    $this->crawler = Crawler::factory()->create();
    $this->circuit = SourceCircuit::factory()->create(['platform_id' => $this->crawler->registry->platform_id]);
});

function actAsSourceOperator(): User
{
    $user = User::factory()->create(['email' => 'operator@agelto.ru']);
    $user->assignRole(OperatorRole::class);
    actingAs($user);

    return $user;
}

test('оператор ставит источник на паузу из админки: инцидент с автором', function (): void {
    actAsSourceOperator();

    Livewire::test(ListSourceCircuits::class)
        ->assertCanSeeTableRecords([$this->circuit])
        ->callTableAction('pause', record: $this->circuit, data: ['reason' => 'Проверяем прокси'])
        ->assertHasNoTableActionErrors();

    expect($this->circuit->fresh()->manually_paused)->toBeTrue()
        ->and(SourceIncident::query()->where('type', SourceIncidentType::ManualPause)->sole()->actor)->toBe('operator@agelto.ru');
});

test('«Возобновить сейчас» снимает ожидание и ставит приостановленные прогоны в очередь без ожидания', function (): void {
    Queue::fake();
    actAsSourceOperator();
    $this->circuit->update(['retry_at' => now()->addHour()]);
    Run::factory()->for($this->crawler)->create([
        'status' => RunStatus::Paused,
        'stats' => ['source_suspended' => true, 'execution_context' => ['mode' => 'fresh', 'execution_lane' => $this->crawler->execution_lane->value, 'from' => null, 'to' => null]],
    ]);

    Livewire::test(ListSourceCircuits::class)
        ->callTableAction('resumeNow', record: $this->circuit)
        ->assertHasNoTableActionErrors();

    expect($this->circuit->fresh()->retry_at)->toBeNull();
    Queue::assertPushed(CrawlRunJob::class);
});

test('оператор правит пороги брейкера', function (): void {
    actAsSourceOperator();

    Livewire::test(EditSourceCircuit::class, ['record' => $this->circuit->getRouteKey()])
        ->fillForm(['failure_threshold' => 12, 'retry_interval_minutes' => 3, 'enabled' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->circuit->fresh()->failure_threshold)->toBe(12)
        ->and($this->circuit->fresh()->retry_interval_minutes)->toBe(3);
});

test('журнал инцидентов: оператор отмечает разобранным; аналитик журнал не видит', function (): void {
    $incident = SourceIncident::factory()->create(['platform_id' => $this->circuit->platform_id]);
    actAsSourceOperator();

    Livewire::test(ListSourceIncidents::class)
        ->assertCanSeeTableRecords([$incident])
        ->callTableAction('acknowledge', record: $incident);

    expect($incident->fresh()->acknowledged_by)->toBe('operator@agelto.ru');

    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    actingAs($analyst);

    $this->get('/admin/procurement/crawler/source-incidents')->assertForbidden();
});
