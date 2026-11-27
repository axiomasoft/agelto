<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Caller;

use App\Services\Procurement\Proxyma\Catalog\ProfileBindings;

/** Только binding consumer; единственный admission gate принадлежит package. */
final readonly class CallerGate
{
    public function __construct(private ProfileBindings $routes) {}

    public function routePolicy(): ProfileBindings
    {
        return $this->routes;
    }
}
