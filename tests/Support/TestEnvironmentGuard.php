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

        if ($app->environment() !== 'testing' || $default !== 'pgsql'
            || filled($config->get("database.connections.{$default}.url"))) {
            throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: нужны APP_ENV=testing, pgsql и пустой DB_URL. Проверьте phpunit.xml/tests/bootstrap.php.');
        }

        if (getenv('PROCESS_CORE_TEST') === '1') {
            self::assertProcessCoreIdentity(app: $app);
        }

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

    public static function assertProcessCoreIdentity(Application $app): void
    {
        $config = $app->make('config');
        $expected = [
            'database.connections.pgsql.host' => 'core-postgres',
            'database.connections.pgsql.username' => 'core_test',
            'database.redis.default.host' => 'core-redis',
            'database.redis.cache.host' => 'core-redis',
            'database.redis.options.prefix' => 'agelto_core_test:',
            'horizon.prefix' => 'agelto_core_test:horizon:',
        ];

        foreach ($expected as $key => $value) {
            if ($config->get($key) !== $value) {
                throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: process-core identity: '.$key.'. Используйте make core-test-up и отдельный Compose project agelto_core_test.');
            }
        }

        if (filled($config->get('database.redis.default.url')) || filled($config->get('database.redis.cache.url'))) {
            throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: process-core identity: Redis URL обходит host guard.');
        }

        $mode = getenv('PROCESS_CORE_QUEUE_MODE') ?: 'sync';

        if (! in_array($mode, ['sync', 'redis'], strict: true) || $config->get('queue.default') !== $mode) {
            throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: process-core queue mode не совпадает с resolved queue.default.');
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

            TestFilesystemIsolation::assertNoSymlinks(path: $path);
        }

        foreach ($config->get('filesystems.disks', []) as $disk) {
            if (($disk['driver'] ?? null) !== 'local' || ! is_string($disk['root'] ?? null)
                || ! str_starts_with($disk['root'], $root.'/disks/')) {
                throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: filesystem disk выходит за process-root.');
            }

            TestFilesystemIsolation::assertNoSymlinks(path: $disk['root']);
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
