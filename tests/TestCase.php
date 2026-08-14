<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestEnvironmentGuard;
use Tests\Support\TestFilesystemIsolation;

abstract class TestCase extends BaseTestCase
{
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

        return $app;
    }
}
