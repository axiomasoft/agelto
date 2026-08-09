<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\User\AdminUserSeeder;
use Illuminate\Console\Command;

final class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password {--force : Confirm the password reset}';

    protected $description = 'Regenerate the initial administrator password';

    public function handle(): int
    {
        if (! $this->option(key: 'force')) {
            $this->error(string: 'Сброс пароля изменяет доступ администратора. Повторите команду с --force.');

            return self::FAILURE;
        }

        $seeder = (new AdminUserSeeder())->setCommand(command: $this);

        return $seeder->resetPassword() ? self::SUCCESS : self::FAILURE;
    }
}
