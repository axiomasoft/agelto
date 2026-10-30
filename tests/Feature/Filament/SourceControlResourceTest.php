<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Crawler\SourceCircuits\Pages\EditSourceCircuit;
use App\Filament\Resources\Procurement\Crawler\SourceCircuits\Pages\ListSourceCircuits;
use App\Filament\Resources\Procurement\Crawler\SourceCircuits\SourceCircuitResource;
use App\Filament\Resources\Procurement\Crawler\SourceIncidents\Pages\ListSourceIncidents;
use App\Filament\Resources\Procurement\Process\ControlScopes\Pages\ListControlScopes;
use App\Filament\Resources\Procurement\Process\Support\OperatorActionInput;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Process\ControlScope;
use App\Models\User;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
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
    app(ProcessTransaction::class)->run(fn () => app(ControlRepository::class)->ensureScopes(SourceCatalogSync::platformScopes($this->crawler->registry->platform->code)));
    $scope = ControlScope::query()->where('scope_key', 'acquisition')->sole();
    $before = $this->circuit->fresh()->getRawOriginal();
    Livewire::test(ListControlScopes::class)->callTableAction('pause', $scope, data: ['reason' => 'Проверяем прокси', OperatorActionInput::FIELD => OperatorActionInput::issue('pause', $scope->id, $scope->control_version)])
        ->assertHasNoTableActionErrors();
    expect($scope->fresh()->desired_mode->value)->toBe('paused')
        ->and(DB::table('prc_process_transitions')->where('actor_id', auth()->id())->count())->toBeGreaterThan(0)
        ->and($this->circuit->fresh()->getRawOriginal())->toEqual($before);
});

test('«Возобновить сейчас» снимает ожидание и ставит приостановленные прогоны в очередь без ожидания', function (): void {
    actAsSourceOperator();
    app(ProcessTransaction::class)->run(fn () => app(ControlRepository::class)->ensureScopes(SourceCatalogSync::platformScopes($this->crawler->registry->platform->code)));
    $scope = ControlScope::query()->where('scope_key', 'acquisition')->sole();
    Livewire::test(ListControlScopes::class)->callTableAction('pause', $scope, data: ['reason' => 'Пауза', OperatorActionInput::FIELD => OperatorActionInput::issue('pause', $scope->id, $scope->control_version)]);
    $scope->refresh();
    $before = $this->circuit->fresh()->getRawOriginal();
    Livewire::test(ListControlScopes::class)->callTableAction('resume', $scope, data: ['reason' => 'Проверка завершена', OperatorActionInput::FIELD => OperatorActionInput::issue('resume', $scope->id, $scope->control_version)])
        ->assertHasNoTableActionErrors();
    expect($scope->fresh()->desired_mode->value)->toBe('enabled')
        ->and($this->circuit->fresh()->getRawOriginal())->toEqual($before);
});

test('оператор правит пороги брейкера', function (): void {
    actAsSourceOperator();
    $before = $this->circuit->fresh()->getRawOriginal();
    expect(array_keys(SourceCircuitResource::getPages()))->toBe(['index']);
    $this->get(SourceCircuitResource::getUrl().'/'.$this->circuit->getRouteKey().'/edit')->assertNotFound();
    Livewire::test(EditSourceCircuit::class, ['record' => $this->circuit->getRouteKey()])->assertForbidden();
    expect($this->circuit->fresh()->getRawOriginal())->toEqual($before);
});

test('исторический список и sidebar открываются без старых mutation actions', function (): void {
    actAsSourceOperator();
    Livewire::test(ListSourceCircuits::class)->assertCanSeeTableRecords([$this->circuit])
        ->assertTableActionDoesNotExist('pause')->assertTableActionDoesNotExist('resume')->assertTableActionDoesNotExist('edit');
    $this->get(SourceCircuitResource::getUrl())->assertOk()->assertSee('Действующее управление процессами');
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
