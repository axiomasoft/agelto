<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum AiRoutePermission: string
{
    case ViewAny = 'ai_route.view_any';
    case View = 'ai_route.view';
    case Create = 'ai_route.create';
    case Update = 'ai_route.update';
    case Delete = 'ai_route.delete';
    case Restore = 'ai_route.restore';
    case ForceDelete = 'ai_route.force_delete';
    case Replicate = 'ai_route.replicate';
    case Reorder = 'ai_route.reorder';
}
