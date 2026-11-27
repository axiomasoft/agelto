<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\Core\StageCode;
use App\Filament\Resources\Procurement\OperationLogs\OperationLogResource;
use App\Filament\Resources\Procurement\OperationLogs\Pages\ListOperationLogs;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Process\ControlScope;
use App\Models\Procurement\Process\Transition;
use App\Models\User;
use App\Services\Procurement\Process\Diagnostics\ProcessOperatorCommands;
use Livewire\Livewire;
use Tests\Support\ProcessCore\ExecutionFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(fn () => artisan('guard:sync-roles')->assertExitCode(0));

test('оператор читает причину ошибки и объект в журнале процессов без права изменения', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator)->get(OperationLogResource::getUrl())->assertOk();
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    $scope = ControlScope::query()->where('scope_key', 'acquisition')->sole();
    app(ProcessOperatorCommands::class)->pauseScope($operator, $scope->scope_key, $scope->control_version,
        'Соединение с площадкой прервано <script>alert(1)</script>');
    $log = Transition::query()->where('control_id', $scope->id)->sole();

    Livewire::test(ListOperationLogs::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$log])
        ->assertTableColumnStateSet('details.reason', $log->details['reason'], record: $log)
        ->assertSee('Соединение с площадкой прервано')
        ->assertDontSeeHtml('<script>alert(1)</script>');
    expect(OperationLogResource::canCreate())->toBeFalse()
        ->and(OperationLogResource::canEdit($log))->toBeFalse()
        ->and(OperationLogResource::canDelete($log))->toBeFalse();
});

test('аналитик не получает доступ к техническому журналу по прямому URL', function (): void {
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    actingAs($analyst)->get(OperationLogResource::getUrl())->assertForbidden();
});
