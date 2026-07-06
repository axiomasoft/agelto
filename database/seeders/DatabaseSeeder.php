<?php

namespace Database\Seeders;

use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // PHP-роли зеркалятся в таблицу roles до назначения (idempotent, безопасно всегда).
        Artisan::call('guard:sync-roles');

        // Демо-аккаунты (в т.ч. суперадмин с wildcard) — ТОЛЬКО в local/testing.
        // На prod бутстрап суперадмина — через `php artisan guard:super-admin --user=`,
        // иначе db:seed на деплое создал бы бэкдор с предсказуемым паролем.
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $password = Hash::make(env('DEV_SEED_PASSWORD', 'password'));

        $accounts = [
            ['Super Admin', 'admin@agelto.local', SuperAdminRole::class],
            ['Operator', 'operator@agelto.local', OperatorRole::class],
            ['Analyst', 'analyst@agelto.local', AnalystRole::class],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            User::factory()
                ->create(['name' => $name, 'email' => $email, 'password' => $password])
                ->assignRole($role);
        }
    }
}
