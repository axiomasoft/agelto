<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Crawler\SourceIncidents\Pages\ListSourceIncidents;
use App\Filament\Resources\Procurement\Crawler\SourceIncidents\SourceIncidentResource;
use App\Filament\Resources\Procurement\Process\ControlScopes\ControlScopeResource;
use App\Filament\Resources\Procurement\Process\ControlScopes\Pages\ListControlScopes;
use App\Filament\Resources\Procurement\Process\Support\OperatorActionInput;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Process\ControlScope;
use App\Models\User;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ProcessTransaction;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function (): void {
    artisan('guard:sync-roles')->assertExitCode(0);
    $this->crawler = EisCrawlerFactory::new()->create();
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
    Livewire::test(ListControlScopes::class)->callTableAction('pause', $scope, data: ['reason' => 'Проверяем прокси', OperatorActionInput::FIELD => OperatorActionInput::issue('pause', $scope->id, $scope->control_version)])
        ->assertHasNoTableActionErrors();
    expect($scope->fresh()->desired_mode->value)->toBe('paused')
        ->and(DB::table('prc_process_transitions')->where('actor_id', auth()->id())->count())->toBeGreaterThan(0);
});

test('«Возобновить сейчас» снимает ожидание и ставит приостановленные прогоны в очередь без ожидания', function (): void {
    actAsSourceOperator();
    app(ProcessTransaction::class)->run(fn () => app(ControlRepository::class)->ensureScopes(SourceCatalogSync::platformScopes($this->crawler->registry->platform->code)));
    $scope = ControlScope::query()->where('scope_key', 'acquisition')->sole();
    Livewire::test(ListControlScopes::class)->callTableAction('pause', $scope, data: ['reason' => 'Пауза', OperatorActionInput::FIELD => OperatorActionInput::issue('pause', $scope->id, $scope->control_version)]);
    $scope->refresh();
    Livewire::test(ListControlScopes::class)->callTableAction('resume', $scope, data: ['reason' => 'Проверка завершена', OperatorActionInput::FIELD => OperatorActionInput::issue('resume', $scope->id, $scope->control_version)])
        ->assertHasNoTableActionErrors();
    expect($scope->fresh()->desired_mode->value)->toBe('enabled');
});

test('legacy circuits routes and schema are gone while the complete operator sidebar renders', function (): void {
    actAsSourceOperator();
    expect(Schema::hasTable('prc_source_circuits'))->toBeFalse();
    $this->get('/admin/procurement/crawler/source-circuits')->assertNotFound();
    $this->get('/admin/procurement/crawler/source-circuits/1/edit')->assertNotFound();
    $this->get(ControlScopeResource::getUrl())->assertOk();
    $this->get(SourceIncidentResource::getUrl())->assertOk();
});

test('журнал инцидентов: оператор отмечает разобранным; аналитик журнал не видит', function (): void {
    $incident = SourceIncident::factory()->create(['platform_id' => $this->crawler->registry->platform_id]);
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
