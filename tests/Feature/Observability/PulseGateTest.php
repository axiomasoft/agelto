<?php

declare(strict_types=1);

use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

beforeEach(fn () => artisan('guard:sync-roles'));

test('viewPulse — персонал панели (роль или суперадмин) проходит, гость без ролей — нет', function (): void {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(SuperAdminRole::class);

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    $combined = User::factory()->create();
    $combined->assignRole(AnalystRole::class, OperatorRole::class);

    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);

    $guest = User::factory()->create();

    expect(Gate::forUser($superAdmin)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($combined)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($analyst)->allows('viewPulse'))->toBeFalse()
        ->and(Gate::forUser($guest)->allows('viewPulse'))->toBeFalse()
        ->and(Gate::allows('viewPulse'))->toBeFalse();

    get('/pulse')->assertForbidden();
    actingAs($guest)->get('/pulse')->assertForbidden();
    actingAs($analyst)->get('/pulse')->assertForbidden();
    actingAs($operator)->get('/pulse')->assertOk();
    actingAs($superAdmin)->get('/pulse')->assertOk();
    actingAs($combined)->get('/pulse')->assertOk();
});
