<?php

declare(strict_types=1);

use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Ai\Analysis;
use App\Models\User;

use function Pest\Laravel\artisan;

test('оператор авторизован на действия карточки анализа (update-право), аналитик — только на чтение', function (): void {
    artisan('guard:sync-roles');

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);

    $analysis = Analysis::factory()->create();

    expect($operator->can('admin.analysis.view_any'))->toBeTrue()
        ->and($operator->can('update', $analysis))->toBeTrue()
        ->and($analyst->can('admin.analysis.view_any'))->toBeTrue()
        ->and($analyst->can('update', $analysis))->toBeFalse();
});
