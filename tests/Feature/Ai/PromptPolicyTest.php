<?php

declare(strict_types=1);

use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Ai\Prompt;
use App\Models\User;

use function Pest\Laravel\artisan;

// PHP-роли зеркалятся в таблицу roles до назначения (class_name → assignRole).
beforeEach(fn () => artisan('guard:sync-roles'));

test('оператор редактирует и удаляет операторский (не-protected) промт', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    $prompt = Prompt::factory()->create(['is_protected' => false]);

    expect($operator->can('update', $prompt))->toBeTrue()
        ->and($operator->can('delete', $prompt))->toBeTrue();
});

test('оператор не может редактировать и удалять канонический (protected) промт несмотря на право', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    $prompt = Prompt::factory()->create(['is_protected' => true]);

    expect($operator->can('admin.prompt.update'))->toBeTrue()
        ->and($operator->can('update', $prompt))->toBeFalse()
        ->and($operator->can('delete', $prompt))->toBeFalse();
});

test('пользователь без права на промты не может редактировать даже операторский промт', function (): void {
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);

    $prompt = Prompt::factory()->create(['is_protected' => false]);

    expect($analyst->can('update', $prompt))->toBeFalse()
        ->and($analyst->can('delete', $prompt))->toBeFalse();
});
