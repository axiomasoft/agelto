<?php

declare(strict_types=1);

use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\artisan;

beforeEach(fn () => artisan('guard:sync-roles'));

test('viewPulse — персонал панели (роль или суперадмин) проходит, гость без ролей — нет', function (): void {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(SuperAdminRole::class);

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    $guest = User::factory()->create();

    expect(Gate::forUser($superAdmin)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($guest)->allows('viewPulse'))->toBeFalse()
        ->and(Gate::allows('viewPulse'))->toBeFalse();
});
