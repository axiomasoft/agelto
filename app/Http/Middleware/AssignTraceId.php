<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сквозной `trace_id` (D25, ТЗ research/J §1): `Context::add()` дегидрируется в payload
 * КАЖДОГО job'а, диспатченного в рамках запроса, и гидрируется обратно в воркере — те же
 * значения едут в логи job'а без ручной прокидки (канон Laravel Context, laravel.com/docs
 * context). Консольный эквивалент — `cmd:<name>:<uuid>` (Code Guidance, точки старта команд
 * пайплайна вне HTTP — `app/Console/Commands/Procurement/*`).
 */
final class AssignTraceId
{
    public function handle(Request $request, Closure $next): Response
    {
        Context::add('trace_id', (string) Str::uuid());
        Context::add('url', $request->fullUrl());

        return $next($request);
    }
}
