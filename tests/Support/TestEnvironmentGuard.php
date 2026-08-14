<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
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
     * БД активного подключения обязана быть ровно serial/test-token именем Agelto.
     *
     * RefreshDatabase выполняет migrate:fresh — запуск по dev-БД уничтожит данные.
     */
    public static function assertBootstrapTestDatabase(Application $app): void
    {
        $config = $app->make('config');

        $default = (string) $config->get('database.default');
        $database = $config->get("database.connections.{$default}.database");

        if ($database !== 'agelto_test') {
            throw new RuntimeException(sprintf(
                "ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: bootstrap DB не является exact test DB.\n".
                    "  connection: [%s], database: [%s]\n".
                    "  ожидается: [%s]\n".
                    'До Laravel ParallelTesting callback тесты должны видеть только canonical DB, '.
                    "потому что RefreshDatabase вызывает migrate:fresh и СОТРЁТ данные dev-БД.\n".
                    'Как починить:'."\n".
                    "  1. Убедитесь, что phpunit.xml содержит APP_ENV=testing и DB_DATABASE=agelto_test.\n".
                    "  2. Проверьте tests/bootstrap.php — он должен выставлять DB_DATABASE до vendor/autoload.\n".
                    '  3. Создайте тестовую БД: make db:create-test (идемпотентно).',
                $default,
                is_string($database) ? $database : (string) json_encode($database),
                'agelto_test',
            ));
        }
    }

    public static function assertParallelTestDatabase(Application $app, string $database, string $token): void
    {
        $token = self::normalizeToken(token: $token);
        $expected = "agelto_test_test_{$token}";
        $config = $app->make('config');
        $default = (string) $config->get('database.default');
        $resolved = $config->get("database.connections.{$default}.database");

        if ($database !== $expected || $resolved !== $expected) {
            throw new RuntimeException(sprintf(
                "ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: Laravel parallel worker выбрал неверную БД.\n".
                    "  token: [%s], callback database: [%s], resolved database: [%s]\n".
                    "  ожидается: [%s]\n".
                    'Проверьте bootstrap DB, Laravel ParallelTesting hook и не заменяйте exact check regex-ом.',
                $token,
                $database,
                is_string($resolved) ? $resolved : (string) json_encode($resolved),
                $expected,
            ));
        }
    }

    public static function assertIsolatedTestFilesystem(Application $app): void
    {
        $config = $app->make('config');
        $root = $config->get('testing.filesystem_root');

        if (! is_string($root) || ! str_starts_with($root, storage_path('framework/testing/'))) {
            throw new RuntimeException(
                'ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: файловая система не направлена в storage/framework/testing. '.
                'Проверьте Tests\\Support\\TestFilesystemIsolation до первого обращения к Storage.'
            );
        }

        foreach (['log_root', 'temp_root', 'auth_root', 'artifact_root'] as $name) {
            $path = $config->get("testing.{$name}");

            if (! is_string($path) || ! str_starts_with($path, $root.'/')) {
                throw new RuntimeException("ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: {$name} выходит за process-root.");
            }
        }

        if (sys_get_temp_dir() !== $config->get('testing.temp_root')) {
            throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: PHP temp root не совпадает с process-root.');
        }
    }

    private static function normalizeToken(string $token): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: Laravel parallel token содержит недопустимые символы.');
        }

        return $token;
    }
}
