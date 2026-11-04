<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP trace_id сохраняется в Laravel Context, дегидрируется в payload отправленных
 * jobs и восстанавливается в worker. Console commands используют `cmd:<name>:<uuid>`.
 *
 * Middleware работает до роутера. Имя и шаблон route записываются на RouteMatched,
 * после сопоставления запроса и до вызова handler.
 */
final class AssignTraceId
{
    public function handle(Request $request, Closure $next): Response
    {
        Context::add('trace_id', (string) Str::uuid());
        self::rememberRoute($request);
        self::listenForMatchedRoute();

        $response = $next($request);

        if (! $response instanceof Response) {
            throw new \UnexpectedValueException('HTTP middleware must return a response.');
        }

        return $response;
    }

    /**
     * Один listener на dispatcher текущего контейнера. Статический флаг не подходит: тесты пересоздают
     * application, а Octane держит один dispatcher на много запросов.
     */
    private static function listenForMatchedRoute(): void
    {
        $key = self::class.'.route-listener';
        if (app()->bound($key)) {
            return;
        }
        app()->instance($key, true);
        Event::listen(RouteMatched::class, static function (RouteMatched $event): void {
            self::rememberRoute($event->request);
        });
    }

    /** Только route name + origin + шаблон пути: raw fullUrl несёт query, signed token и ID (observability#s05). */
    private static function rememberRoute(Request $request): void
    {
        $route = $request->route();
        if (! $route instanceof Route) {
            Context::add('url', $request->getSchemeAndHttpHost().'/[unrouted]');
            Context::forget('route');

            return;
        }
        Context::add('url', $request->getSchemeAndHttpHost().'/'.ltrim($route->uri(), '/'));
        if ($route->getName() !== null) {
            Context::add('route', $route->getName());

            return;
        }
        Context::forget('route');
    }
}
