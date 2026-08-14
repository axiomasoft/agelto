<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\CreateInitialAdminAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Description('Create the initial SuperAdmin from hidden input or a password file')]
#[Signature('admin:create-initial {--email= : Email initial administrator} {--password-file= : Read the password from this file}')]
final class CreateInitialAdmin extends Command
{
    public function handle(CreateInitialAdminAction $action): int
    {
        try {
            $action->run(
                email: (string) ($this->option(key: 'email') ?: config(key: 'cms.admin_email')),
                password: $this->password(),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error(string: $exception->getMessage());

            return self::FAILURE;
        }

        $this->info(string: 'Начальный администратор создан.');

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

        return (string) $this->secret(question: 'Пароль начального администратора');
    }
}
