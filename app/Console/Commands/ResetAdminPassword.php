<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\ResetAdminPasswordAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Description('Regenerate the initial administrator password')]
#[Signature('admin:reset-password {--force : Confirm the password reset} {--password-file= : Read the password from this file}')]
final class ResetAdminPassword extends Command
{
    public function handle(ResetAdminPasswordAction $action): int
    {
        if (! $this->option(key: 'force')) {
            $this->error(string: 'Сброс пароля изменяет доступ администратора. Повторите команду с --force.');

            return self::FAILURE;
        }

        try {
            $action->run(
                email: $this->email(),
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

        $password = $this->secret(question: 'Новый пароль администратора');

        if (! is_string($password)) {
            throw new InvalidArgumentException('Пароль должен быть строкой.');
        }

        return $password;
    }

    private function email(): string
    {
        $email = config(key: 'cms.admin_email');

        if (! is_string($email)) {
            throw new InvalidArgumentException('cms.admin_email must be a string.');
        }

        return $email;
    }
}
