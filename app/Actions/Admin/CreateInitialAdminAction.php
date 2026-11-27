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

        if (mb_strlen(trim($password)) < 8) {
            throw new InvalidArgumentException('Пароль должен содержать не менее 8 символов.');
        }

        $user = DB::transaction(callback: function () use ($email, $password): User {
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

            return $user;
        });

        // AzGuard denies checks inside an open transaction on its connection, so the
        // role is verified after commit and the user is removed if it did not stick.
        $persistedUser = $user->fresh();

        if (! $persistedUser instanceof User || ! $persistedUser->isSuperAdmin()) {
            $user->delete();

            throw new InvalidArgumentException('Роль SuperAdmin не синхронизирована; администратор не создан.');
        }

        return $user;
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
