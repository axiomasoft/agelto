<?php

declare(strict_types=1);

use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Support\Telemetry\SafeLogManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

require_once __DIR__.'/proxyma.php';

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([__DIR__.'/../app/Console/Platforms'])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        $middleware->append(SecurityHeadersMiddleware::class);

        // Сквозной trace_id (P7.5, D25) — на все HTTP-запросы, дегидрируется в jobs-payload.
        $middleware->append(AssignTraceId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error-tracking (P7.5, D25) — no-op пока `SENTRY_LARAVEL_DSN` пуст (fail-open,
        // Implementation Rules); DSN/прод-хост GlitchTip — Pending Work владельца.
        Integration::handles($exceptions);
    })->create();

$app->singleton('log', static fn (Application $app): SafeLogManager => new SafeLogManager($app));

return $app;
