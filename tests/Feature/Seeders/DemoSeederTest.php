<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

test('не перезаписывает существующего demo пользователя при повторном сиде', function () {
    config(['cms.demo_seed_password' => 'demo-password']);
    $existing = User::factory()->create(attributes: [
        'email' => 'admin@agelto.local',
        'password' => 'existing-password',
    ]);

    app(DemoSeeder::class)->run();
    app(DemoSeeder::class)->run();

    expect(User::query()->count())->toBe(3)
        ->and(Hash::check(value: 'existing-password', hashedValue: $existing->fresh()->password))->toBeTrue();
});

test('отказывается выполнять demo сид вне local или testing', function () {
    config(['cms.demo_seed_password' => 'demo-password']);
    app()->detectEnvironment(callback: fn (): string => 'production');

    expect(fn () => app(DemoSeeder::class)->run())
        ->toThrow(LogicException::class, 'DemoSeeder разрешён только');
});
