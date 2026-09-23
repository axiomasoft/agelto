<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\OperationLogs\OperationLogResource;
use App\Filament\Resources\Procurement\OperationLogs\Pages\ListOperationLogs;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\OperationLog;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(fn () => artisan('guard:sync-roles')->assertExitCode(0));

test('оператор читает причину ошибки и объект в журнале процессов без права изменения', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator)->get(OperationLogResource::getUrl())->assertOk();
    $log = OperationLog::factory()->create([
        'context' => ['error' => 'Соединение с площадкой прервано <script>alert(1)</script>'],
        'entity_type' => 'document',
        'entity_id' => 42,
    ]);

    Livewire::test(ListOperationLogs::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$log])
        ->assertTableColumnStateSet('context.error', $log->context['error'], record: $log)
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
