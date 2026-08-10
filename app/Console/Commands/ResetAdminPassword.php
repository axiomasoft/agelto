<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\ResetAdminPasswordAction;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password {--force : Confirm the password reset} {--password-file= : Read the password from this file}';

    protected $description = 'Regenerate the initial administrator password';

    public function handle(ResetAdminPasswordAction $action): int
    {
        if (! $this->option(key: 'force')) {
            $this->error(string: 'Сброс пароля изменяет доступ администратора. Повторите команду с --force.');

            return self::FAILURE;
        }

        try {
            $action->run(
                email: (string) config(key: 'cms.admin_email'),
                password: $this->password(),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error(string: $exception->getMessage());

            return self::FAILURE;
        }

        $this->info(string: 'Пароль администратора обновлён.');

        return self::SUCCESS;
    }

    private function password(): string
    {
        $path = $this->option(key: 'password-file');

        if (is_string($path) && $path !== '') {
            if (! is_readable($path)) {
                throw new InvalidArgumentException('Файл пароля недоступен для чтения.');
            }

            return rtrim((string) file_get_contents($path), "\r\n");
        }

        if (! $this->input->isInteractive()) {
            throw new InvalidArgumentException('Для неинтерактивного запуска укажите --password-file.');
        }

        return (string) $this->secret(question: 'Новый пароль администратора');
    }
}
