<?php

namespace Tests;

use App\Services\Procurement\Process\StageRegistry;
use AzGuard\Testing\InteractsWithAzGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
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
        // Core fixtures bind their own handlers explicitly. Runtime composition tests resolve
        // the application registration after forgetting this deliberately empty fixture registry.
        $app->instance(StageRegistry::class, new StageRegistry);

        return $app;
    }
}
