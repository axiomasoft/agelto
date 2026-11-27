<?php

namespace Tests;

use App\Services\Procurement\Process\Execution\StageRegistry;
use AzGuard\Testing\InteractsWithAzGuard;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Proxyma\Infrastructure\Database\PackageSchema;
use Tests\Support\TestEnvironmentGuard;
use Tests\Support\TestFilesystemIsolation;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithAzGuard;

    /**
     * Падает сразу, если тесты подключились к dev-БД (RefreshDatabase выполняет
     * migrate:fresh — это уничтожило бы данные).
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        TestFilesystemIsolation::configure($app);
        TestEnvironmentGuard::assertBootstrapTestDatabase($app);
        TestEnvironmentGuard::assertIsolatedTestFilesystem($app);
        Http::preventStrayRequests();
        $app['events']->listen(MigrationsStarted::class, static function (MigrationsStarted $event): void {
            $connection = DB::connection();
            if ($event->method !== 'up' || $connection->getDriverName() !== 'pgsql'
                || $connection->getSchemaBuilder()->hasTable(PackageSchema::tables()['attempts'])) {
                return;
            }

            // migrate:fresh drops tables and triggers, leaving their functions in the test schema.
            $functions = $connection->select(<<<'SQL'
                SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = current_schema() AND left(p.proname, 4) = 'pxm_'
                    AND p.prorettype = 'trigger'::regtype AND p.pronargs = 0
                SQL);
            foreach ($functions as $function) {
                $name = $connection->getQueryGrammar()->wrap($function->proname);
                $connection->statement("DROP FUNCTION {$name}()");
            }
        });
        // Core fixtures bind their own handlers explicitly. Runtime composition tests resolve
        // the application registration after forgetting this deliberately empty fixture registry.
        $app->instance(StageRegistry::class, new StageRegistry);

        return $app;
    }
}
