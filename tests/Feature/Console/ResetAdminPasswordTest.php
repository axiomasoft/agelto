<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

test('сбрасывает пароль администратора только с явным --force', function () {
    config(['cms.admin_email' => 'admin@example.test']);

    $admin = User::factory()->create(attributes: [
        'email' => 'admin@example.test',
        'password' => 'old-password',
    ]);

    $this->artisan('admin:reset-password')
        ->expectsOutputToContain('Повторите команду с --force.')
        ->assertExitCode(Command::FAILURE);

    expect(Hash::check(value: 'old-password', hashedValue: $admin->fresh()->password))->toBeTrue();

    $this->artisan('admin:reset-password', ['--force' => true])
        ->expectsOutputToContain('Пользователь админки: admin@example.test')
        ->assertExitCode(Command::SUCCESS);

    $admin->refresh();

    expect(Hash::check(value: 'old-password', hashedValue: $admin->password))->toBeFalse();
});
