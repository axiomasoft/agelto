<?php

declare(strict_types=1);

namespace Tests\Support\Postgres;

use Illuminate\Support\Facades\ParallelTesting;
use RuntimeException;
use Tests\Support\TestEnvironmentGuard;

/**
 * Запускает критические секции в отдельных PHP-процессах с реальными PostgreSQL-соединениями.
 *
 * Каждый worker загружает testing-приложение, сообщает readiness через stdout и ждёт общий
 * stdin-барьер. Это исключает недетерминированные sleep из race-regressions.
 */
final class ConcurrentTransactions
{
    /**
     * @return array<string, mixed>
     */
    public static function execute(string $code): array
    {
        $process = self::open(code: $code, waitForBarrier: false);
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);
        $exitCode = proc_close($process['process']);

        return self::result(stdout: $stdout, stderr: $stderr, exitCode: $exitCode);
    }

    /**
     * @param  array<string, string>  $workers  Идентификатор worker → тело с `return`.
     * @return array<string, array<string, mixed>>
     */
    public static function concurrently(array $workers): array
    {
        $processes = [];

        foreach ($workers as $name => $code) {
            $processes[$name] = self::open(code: $code, waitForBarrier: true);
        }

        foreach ($processes as $process) {
            $ready = fgets($process['pipes'][1]);

            if ($ready !== "ready\n") {
                $stderr = stream_get_contents($process['pipes'][2]);
                proc_close($process['process']);

                throw new RuntimeException("PostgreSQL worker did not reach the barrier: {$stderr}");
            }
        }

        foreach ($processes as $process) {
            fwrite($process['pipes'][0], "go\n");
            fclose($process['pipes'][0]);
        }

        $results = [];

        foreach ($processes as $name => $process) {
            $stdout = stream_get_contents($process['pipes'][1]);
            $stderr = stream_get_contents($process['pipes'][2]);
            $exitCode = proc_close($process['process']);
            $results[$name] = self::result(stdout: $stdout, stderr: $stderr, exitCode: $exitCode);
        }

        return $results;
    }

    /**
     * @return array{process: resource, pipes: array<int, resource>}
     */
    private static function open(string $code, bool $waitForBarrier): array
    {
        $database = config('database.connections.pgsql.database');
        $token = ParallelTesting::token();
        if ($token === false) {
            TestEnvironmentGuard::assertBootstrapTestDatabase(app());
        } else {
            TestEnvironmentGuard::assertParallelTestDatabase(app(), $database, (string) $token);
        }
        $databaseExport = self::export($database);
        $tokenExport = self::export($token === false ? false : (string) $token);
        $bootstrap = base_path('bootstrap/app.php');
        $autoload = base_path('vendor/autoload.php');
        $payload = base64_encode($code);
        $bootstrapExport = self::export($bootstrap);
        $autoloadExport = self::export($autoload);
        $waitForBarrierExport = self::export($waitForBarrier);
        $workerCode = self::decode($payload);
        $launcher = <<<PHP
putenv('APP_ENV=testing');
putenv('DB_DATABASE=agelto_test');
\$_SERVER['APP_ENV'] = 'testing';
\$_SERVER['DB_DATABASE'] = 'agelto_test';
\$_ENV['APP_ENV'] = 'testing';
\$_ENV['DB_DATABASE'] = 'agelto_test';
require {$autoloadExport};
\$app = require {$bootstrapExport};
\$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$app->instance(\App\Services\Procurement\Process\StageRegistry::class, new \App\Services\Procurement\Process\StageRegistry);
config(['database.connections.pgsql.database' => 'agelto_test']);
\Illuminate\Support\Facades\DB::purge('pgsql');
\Tests\Support\TestFilesystemIsolation::configure(\$app);
\Tests\Support\TestEnvironmentGuard::assertBootstrapTestDatabase(\$app);
\Tests\Support\TestEnvironmentGuard::assertIsolatedTestFilesystem(\$app);
if ({$tokenExport} !== false) {
    config(['database.connections.pgsql.database' => {$databaseExport}]);
    \Illuminate\Support\Facades\DB::purge('pgsql');
    \Tests\Support\TestEnvironmentGuard::assertParallelTestDatabase(\$app, {$databaseExport}, {$tokenExport});
}
if ({$waitForBarrierExport}) {
    fwrite(STDOUT, "ready\\n");
    fgets(STDIN);
}
try {
    \$result = (static function (): mixed {
        {$workerCode}
    })();
    fwrite(STDOUT, json_encode(\$result, JSON_THROW_ON_ERROR)."\\n");
} catch (\Throwable \$exception) {
    fwrite(STDERR, (string) \$exception);
    exit(1);
}
PHP;

        $process = proc_open(
            command: [PHP_BINARY, '-r', $launcher],
            descriptor_spec: [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            pipes: $pipes,
            options: ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start PostgreSQL concurrency worker.');
        }

        return ['process' => $process, 'pipes' => $pipes];
    }

    private static function decode(string $payload): string
    {
        return base64_decode($payload, true) ?: throw new RuntimeException('Could not decode PostgreSQL worker payload.');
    }

    private static function export(string|bool $value): string
    {
        return var_export($value, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(string $stdout, string $stderr, int $exitCode): array
    {
        if ($exitCode !== 0) {
            throw new RuntimeException("PostgreSQL worker failed: {$stderr}");
        }

        try {
            $result = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException("PostgreSQL worker returned invalid JSON: {$stdout}", previous: $exception);
        }

        if (! is_array($result)) {
            throw new RuntimeException('PostgreSQL worker must return a JSON object.');
        }

        return $result;
    }
}
