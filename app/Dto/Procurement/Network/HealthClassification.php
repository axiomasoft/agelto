<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Enums\Procurement\Network\HealthScope;
use App\Enums\Procurement\Network\HealthSignal;

final readonly class HealthClassification
{
    public function __construct(public HealthSignal $route, public HealthSignal $routeTarget, public HealthSignal $target, public string $reason) {}

    public function signal(HealthScope $scope): HealthSignal
    {
        return match ($scope) {
            HealthScope::Route => $this->route,
            HealthScope::RouteTarget => $this->routeTarget,
            HealthScope::Target => $this->target,
        };
    }
}
