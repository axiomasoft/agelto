<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            [
                'email' => 'admin@agelto.ru',
            ],
            [
                'name' => 'Admin',
                'password' => '123456', // хешируется через cast модели User
            ]
        );
    }
}
