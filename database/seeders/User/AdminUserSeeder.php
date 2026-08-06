<?php

declare(strict_types=1);

namespace Database\Seeders\User;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

final class AdminUserSeeder extends Seeder
{
    /**
     * Создаёт администратора по email из конфига, если его ещё нет.
     * Generic `db:seed --force` нужен Laravel для production, но никогда не
     * означает сброс пароля существующего пользователя.
     */
    public function run(): void
    {
        $email = Config::string(key: 'app.cms.admin_email');
        $existing = User::query()->where(column: 'email', operator: '=', value: $email)->first();

        if ($existing === null) {
            $plainPassword = $this->generatePlainPassword();
            User::query()->create(attributes: [
                'email' => $email,
                'name' => 'Administrator',
                'password' => $plainPassword,
                'email_verified_at' => now(),
            ]);
            $this->printPasswordInstructions(plainPassword: $plainPassword);

            return;
        }

        $this->command->newLine();
        $this->command->warn(string: 'Администратор уже существует; пароль не изменён.');
        $this->command->line(string: 'Для осознанного сброса используйте: php artisan admin:reset-password --force');
        $this->command->newLine();
    }

    /** Regenerate an existing administrator password through the explicit command only. */
    public function resetPassword(): bool
    {
        $email = Config::string(key: 'app.cms.admin_email');
        $existing = User::query()->where(column: 'email', operator: '=', value: $email)->first();

        if ($existing === null) {
            $this->command->error(string: 'Администратор не найден. Сначала запустите AdminUserSeeder.');

            return false;
        }

        $plainPassword = $this->generatePlainPassword();
        $existing->update(attributes: [
            'name' => 'Administrator',
            'password' => $plainPassword,
            'email_verified_at' => now(),
        ]);
        $this->printPasswordInstructions(plainPassword: $plainPassword);

        return true;
    }

    /**
     * Сгенерировать пароль для вывода в консоль (один раз).
     */
    private function generatePlainPassword(): string
    {
        return Str::password(
            length: 24,
            letters: true,
            numbers: true,
            symbols: true,
            spaces: false,
        );
    }

    /**
     * Вывести email и одноразовый пароль в консоль.
     */
    private function printPasswordInstructions(string $plainPassword): void
    {
        $this->command->newLine();
        $this->command->warn(string: 'Пользователь админки: '.Config::string(key: 'app.cms.admin_email'));
        $this->command->line(string: 'Пароль (скопируйте сейчас, повторно не показывается):');
        $this->command->info(string: $plainPassword);
        $this->command->newLine();
    }
}
