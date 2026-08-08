<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestEnvironmentGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Падает сразу, если тесты подключились к dev-БД (RefreshDatabase выполняет
     * migrate:fresh — это уничтожило бы данные).
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        TestEnvironmentGuard::assertIsolatedTestDatabase($app);
        Http::preventStrayRequests();

        return $app;
    }
}
