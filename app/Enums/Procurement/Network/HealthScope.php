<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

enum HealthScope: string
{
    case Route = 'prc_route_health';
    case RouteTarget = 'prc_route_target_health';
    case Target = 'prc_target_health';
}
