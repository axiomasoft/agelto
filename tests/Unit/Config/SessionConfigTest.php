<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

test('без настройки драйвера сессии хранятся в Redis', function (): void {
    $driver = getenv('SESSION_DRIVER');
    $env = $_ENV;
    $server = $_SERVER;

    try {
        putenv('SESSION_DRIVER');
        unset($_ENV['SESSION_DRIVER'], $_SERVER['SESSION_DRIVER']);

        $session = require config_path('session.php');

        expect($session['driver'])->toBe('redis');
    } finally {
        putenv($driver === false ? 'SESSION_DRIVER' : "SESSION_DRIVER={$driver}");
        $_ENV = $env;
        $_SERVER = $server;
    }
});

test('при HTTPS в production запуск требует защищённую cookie сессии', function (mixed $secure): void {
    config([
        'app.debug' => false,
        'app.url' => 'https://example.test',
        'queue.default' => 'redis',
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'session.secure' => $secure,
    ]);
    $app = app();
    $environment = $app->environment();
    $app->instance(abstract: 'env', instance: 'production');

    try {
        expect(fn () => (new AppServiceProvider($app))->boot())->toThrow(
            exception: RuntimeException::class,
            exceptionMessage: 'SESSION_SECURE_COOKIE must be true when APP_URL uses HTTPS in production.',
        );
    } finally {
        $app->instance(abstract: 'env', instance: $environment);
    }
})->with([
    'параметр отсутствует' => [null],
    'параметр выключен' => [false],
    'строка вместо логического значения' => ['true'],
]);
