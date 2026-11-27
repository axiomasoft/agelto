<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

test('сбрасывает пароль администратора только с явным --force', function () {
    config(['cms.admin_email' => 'admin@example.test']);
    $passwordFile = tempnam(sys_get_temp_dir(), 'agelto-admin-password-');
    file_put_contents($passwordFile, 'new-secret-password');

    $admin = User::factory()->create(attributes: [
        'email' => 'admin@example.test',
        'password' => 'old-password',
    ]);

    $this->artisan('admin:reset-password')
        ->expectsOutputToContain('Повторите команду с --force.')
        ->assertExitCode(Command::FAILURE);

    expect(Hash::check(value: 'old-password', hashedValue: $admin->fresh()->password))->toBeTrue();

    $this->artisan('admin:reset-password', ['--force' => true, '--password-file' => $passwordFile])
        ->expectsOutputToContain('Пароль администратора обновлён.')
        ->assertExitCode(Command::SUCCESS);

    $admin->refresh();

    expect(Hash::check(value: 'old-password', hashedValue: $admin->password))->toBeFalse();
    expect(Hash::check(value: 'new-secret-password', hashedValue: $admin->password))->toBeTrue();

    unlink($passwordFile);
});

test('не принимает неинтерактивный reset без файла пароля', function () {
    config(['cms.admin_email' => 'admin@example.test']);
    User::factory()->create(attributes: ['email' => 'admin@example.test']);

    $this->artisan('admin:reset-password', ['--force' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Для неинтерактивного запуска укажите --password-file.')
        ->assertExitCode(Command::FAILURE);
});
