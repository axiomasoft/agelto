<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class CreateInitialAdminAction
{
    public function run(string $email, string $password): User
    {
        $email = $this->normalizeEmail(email: $email);

        if (trim($password) === '') {
            throw new InvalidArgumentException('Пароль не может быть пустым.');
        }

        return DB::transaction(callback: function () use ($email, $password): User {
            $existingUsers = User::query()
                ->whereRaw(sql: 'lower(email) = ?', bindings: [$email])
                ->lockForUpdate()
                ->get();

            if ($existingUsers->isNotEmpty()) {
                throw new InvalidArgumentException('Пользователь с этим email уже существует; создание администратора отменено.');
            }

            $user = User::query()->create(attributes: [
                'email' => $email,
                'name' => 'Administrator',
                'password' => $password,
                'email_verified_at' => now(),
            ]);

            $user->assignRole(SuperAdminRole::class);

            if (! $user->fresh()->isSuperAdmin()) {
                throw new InvalidArgumentException('Роль SuperAdmin не синхронизирована; администратор не создан.');
            }

            return $user;
        });
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Укажите корректный email администратора.');
        }

        return $email;
    }
}
