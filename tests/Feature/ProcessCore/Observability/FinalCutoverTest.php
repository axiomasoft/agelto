<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\OperationLogs\OperationLogResource;
use App\Filament\Resources\Procurement\OperationLogs\Pages\ListOperationLogs;
use App\Filament\Resources\Procurement\Process\ControlScopes\ControlScopeResource;
use App\Filament\Resources\Procurement\Process\Incidents\IncidentResource;
use App\Filament\Resources\Procurement\Process\Incidents\Pages\ListIncidents;
use App\Filament\Resources\Procurement\Process\Runs\ProcessRunResource;
use App\Filament\Resources\Procurement\Process\Work\WorkItemResource;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Procurement\Process\Incident;
use App\Models\Procurement\Process\Transition;
use App\Models\User;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\LegacyOwnershipRules;

pest()->group('legacy-ownership-cutover', 'platform-dependency-boundary', 'acquisition-operations');

beforeEach(fn () => $this->artisan('guard:sync-roles')->assertSuccessful());

test('final schema renders the entire admin sidebar badges filters and audited incident action', function (): void {
    foreach (LegacyOwnershipRules::TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    $admin = User::factory()->create();
    $admin->assignRole(SuperAdminRole::class);
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    $id = LedgerFixtures::uuid();
    app(ProcessTransaction::class)->run(fn () => app(IncidentRepository::class)->open(
        LedgerFixtures::hash('p317-final-sidebar'), 'acquisition', 'operator_test', [],
        $id, LedgerFixtures::uuid(), LedgerFixtures::uuid()));
    $incident = Incident::query()->findOrFail($id->value);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $this->actingAs($admin);
    foreach ([OperationLogResource::class, ControlScopeResource::class, IncidentResource::class, ProcessRunResource::class, WorkItemResource::class] as $resource) {
        $html = $this->get($resource::getUrl())->assertOk()->getContent();
        expect($html)->toContain('Процессы: инциденты')->not->toContain('source-incidents', 'source-circuits');
        $resource::getNavigationBadge();
    }
    expect(IncidentResource::getNavigationBadge())->toBe('1');
    foreach (Route::getRoutes() as $route) {
        expect($route->uri())->not->toContain('source-incidents', 'source-circuits');
    }
    $this->actingAs($operator);
    Livewire::test(ListIncidents::class)->filterTable('state', 'open')
        ->assertCanSeeTableRecords([$incident])
        ->callTableAction('acknowledge', $incident, ['reason' => 'operator checked <script>unsafe</script>'])
        ->assertHasNoTableActionErrors();
    expect($incident->fresh()->state)->toBe('acknowledged')
        ->and($incident->fresh()->acknowledged_by)->toBe($operator->id)
        ->and(IncidentResource::getNavigationBadge())->toBeNull();
    $transition = Transition::query()->where('incident_id', $id->value)->where('actor_id', $operator->id)->sole();
    Livewire::test(ListOperationLogs::class)->filterTable('entity_kind', 'incident')
        ->assertCanSeeTableRecords([$transition])->assertDontSeeHtml('<script>unsafe</script>');
    $this->actingAs($analyst)->get(IncidentResource::getUrl())->assertForbidden();
    foreach (LegacyOwnershipRules::TABLES as $table) {
        expect(implode("\n", $queries))->not->toMatch('/\b'.preg_quote($table, '/').'\b/');
    }
});

test('планировщик запускает только утверждённые команды и heartbeat готовности', function (): void {
    expect(Artisan::all())->not->toHaveKeys(['crawler:progress', 'crawler:prune-history',
        'pipeline:cleanup-temp', 'pipeline:stuck-check', 'pipeline:dispatch-backlog', 'pipeline:retry-failed']);
    $commands = [];
    $callbacks = [];
    foreach (app(Schedule::class)->events() as $event) {
        if ($event instanceof CallbackEvent) {
            $callback = (new ReflectionProperty($event, 'callback'))->getValue($event);
            $reflection = new ReflectionFunction($callback);
            $callbacks[] = $reflection->getClosureScopeClass()?->getName().'::'.$reflection->getName();
        } else {
            preg_match('/(?:^|\s)(partitions:maintain|process:[a-z-]+|proxyma:prune)(?:\s|$)/', $event->command, $match);
            $commands[] = [$match[1] ?? $event->command, $event->expression];
        }
    }
    sort($commands);
    expect($commands)->toBe([
        ['partitions:maintain', '0 0 * * *'],
        ['process:diagnostics', '*/5 * * * *'],
        ['process:health-report', '*/5 * * * *'],
        ['process:retention', '0 * * * *'],
        ['process:schedule-acquisition', '* * * * *'],
        ['proxyma:prune', '0 * * * *'],
    ])->and($callbacks)->toBe(['App\Services\Health\ApplicationReadiness::recordSchedulerHeartbeat']);
    $this->artisan('schedule:list')->assertSuccessful();
    $this->artisan('process:health-report')->assertSuccessful();
});
