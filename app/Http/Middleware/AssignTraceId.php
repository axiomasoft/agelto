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
 * Сквозной `trace_id` (D25, ТЗ research/J §1): `Context::add()` дегидрируется в payload
 * КАЖДОГО job'а, диспатченного в рамках запроса, и гидрируется обратно в воркере — те же
 * значения едут в логи job'а без ручной прокидки (канон Laravel Context, laravel.com/docs
 * context). Консольный эквивалент — `cmd:<name>:<uuid>` (Code Guidance, точки старта команд
 * пайплайна вне HTTP — `app/Console/Commands/Procurement/*`).
 *
 * Middleware висит в global stack до роутера, поэтому route resolver ещё не установлен. Имя и шаблон
 * пути пишутся на RouteMatched — после match и до handler, — а не из $request->route() в этом месте.
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
