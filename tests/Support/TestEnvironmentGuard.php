<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

/**
 * Страж изоляции тестового окружения.
 *
 * Вызывается из TestCase::createApplication() сразу после поднятия приложения и
 * аварийно прерывает прогон, если активная БД — не тестовая. Последний рубеж:
 * даже если phpunit.xml/tests/bootstrap.php сломаны или подменён .env, тест упадёт
 * до первого запроса и не запустит migrate:fresh по dev-базе.
 */
final class TestEnvironmentGuard
{
    /**
     * БД активного подключения обязана заканчиваться на `_test`.
     *
     * RefreshDatabase выполняет migrate:fresh — запуск по dev-БД уничтожит данные.
     */
    public static function assertIsolatedTestDatabase(Application $app): void
    {
        $config = $app->make('config');

        $default = (string) $config->get('database.default');
        $database = $config->get("database.connections.{$default}.database");

        if (! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException(sprintf(
                "ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: активная БД не является тестовой.\n".
                    "  connection: [%s], database: [%s]\n".
                    "Тесты должны работать только с БД, имя которой заканчивается на `_test`, ".
                    "потому что RefreshDatabase вызывает migrate:fresh и СОТРЁТ данные dev-БД.\n".
                    'Как починить:'."\n".
                    "  1. Убедитесь, что phpunit.xml содержит APP_ENV=testing и DB_DATABASE=agelto_test.\n".
                    "  2. Проверьте tests/bootstrap.php — он должен выставлять DB_DATABASE до vendor/autoload.\n".
                    '  3. Создайте тестовую БД: make db:create-test (идемпотентно).',
                $default,
                is_string($database) ? $database : (string) json_encode($database)
            ));
        }
    }
}
