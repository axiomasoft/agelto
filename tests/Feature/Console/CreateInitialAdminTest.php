<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

test('создаёт initial SuperAdmin только из файла пароля', function () {
    Artisan::call(command: 'guard:sync-roles');
    config(['cms.admin_email' => ' Admin@Example.Test ']);
    $passwordFile = tempnam(sys_get_temp_dir(), 'agelto-admin-password-');
    file_put_contents($passwordFile, 'new-secret-password');

    $this->artisan('admin:create-initial', ['--password-file' => $passwordFile])
        ->expectsOutputToContain('Начальный администратор создан.')
        ->assertExitCode(Command::SUCCESS);

    $admin = User::query()->sole();

    expect($admin->email)->toBe('admin@example.test')
        ->and(Hash::check(value: 'new-secret-password', hashedValue: $admin->password))->toBeTrue()
        ->and($admin->isSuperAdmin())->toBeTrue();

    $this->artisan('admin:create-initial', ['--password-file' => $passwordFile])
        ->expectsOutputToContain('Пользователь с этим email уже существует')
        ->assertExitCode(Command::FAILURE);

    unlink($passwordFile);
});
