<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use LogicException;

final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('DemoSeeder разрешён только в local или testing окружении.');
        }

        $password = (string) config(key: 'cms.demo_seed_password');

        if (trim($password) === '') {
            throw new LogicException('DEV_SEED_PASSWORD обязателен для демо-данных.');
        }

        foreach ($this->accounts() as $account) {
            $user = User::query()->firstOrCreate(
                attributes: ['email' => $account['email']],
                values: [
                    'name' => $account['name'],
                    'password' => $password,
                    'email_verified_at' => now(),
                ],
            );

            if ($user->wasRecentlyCreated) {
                $user->assignRole($account['role']);
            }
        }
    }

    /** @return list<array{name: string, email: string, role: class-string}> */
    private function accounts(): array
    {
        return [
            ['name' => 'Super Admin', 'email' => 'admin@agelto.local', 'role' => SuperAdminRole::class],
            ['name' => 'Operator', 'email' => 'operator@agelto.local', 'role' => OperatorRole::class],
            ['name' => 'Analyst', 'email' => 'analyst@agelto.local', 'role' => AnalystRole::class],
        ];
    }
}
