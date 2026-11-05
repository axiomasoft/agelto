<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ResetAdminPasswordAction
{
    public function run(string $email, string $password): void
    {
        $email = strtolower(trim($email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Укажите корректный email администратора.');
        }

        if (trim($password) === '') {
            throw new InvalidArgumentException('Пароль не может быть пустым.');
        }

        if (mb_strlen(trim($password)) < 8) {
            throw new InvalidArgumentException('Пароль должен содержать не менее 8 символов.');
        }

        DB::transaction(callback: function () use ($email, $password): void {
            $users = User::query()
                ->whereRaw(sql: 'lower(email) = ?', bindings: [$email])
                ->lockForUpdate()
                ->get();

            if ($users->count() !== 1) {
                throw new InvalidArgumentException('Администратор не найден или email неоднозначен.');
            }

            $user = $users->sole();
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $rawTable = config('session.table', 'sessions');
            $sessionTable = is_string($rawTable) ? $rawTable : 'sessions';
            if ($sessionTable !== '' && Schema::hasTable($sessionTable)) {
                DB::table($sessionTable)->where('user_id', $user->id)->delete();
            }
        });
    }
}
