<?php

declare(strict_types=1);

use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;
use Database\Seeders\BaseSeeder;
use Illuminate\Console\Command;

test('отказывает readiness при неполном release state', function () {
    $this->artisan('doctor', ['--format' => 'agent', '--only' => 'agelto-release', '--no-interaction' => true])
        ->assertExitCode(Command::FAILURE);
});

test('выводит readiness diagnostics в agent формате для seeded release state', function () {
    $this->seed(BaseSeeder::class);
    config(['cms.admin_email' => 'admin@example.test']);

    $admin = User::factory()->create(['email' => 'admin@example.test']);
    $admin->assignRole(SuperAdminRole::class);

    $this->artisan('doctor', ['--format' => 'agent', '--only' => 'agelto-release', '--no-interaction' => true])
        ->assertExitCode(Command::SUCCESS);
});
